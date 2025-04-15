<?php

namespace App;

use App\Model\Account;
use App\Model\Session;
use Exception;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use NumberFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Webmozart\Assert\Assert;

class BalanceAccountsCommand extends Command
{
    private const CURRENCY = 'EUR';

    private const URL_DASHBOARD_BASE = 'https://dashboard.uberspace.de/dashboard';

    private const URL_OVERVIEW = 'https://dashboard.uberspace.de/meta';

    private const URL_OVERVIEW_SWITCH_ACCOUNT = 'https://dashboard.uberspace.de/meta/switch/%s';

    private ?RemoteWebDriver $driver = null;

    private NumberFormatter $formatter;

    private InputInterface $input;

    private OutputInterface $output;

    protected function configure()
    {
        $this->setName('uberspace:balanceaccount');

        $this->addArgument(
            'source',
            InputArgument::REQUIRED,
            'The name of the source account from which the money should be transferred.'
        );

        $this->addArgument(
            'amount',
            InputArgument::REQUIRED,
            'The amount to which the accounts should be filled up.'
        );

        $this->addOption(
            'serverUrl',
            'u',
            InputOption::VALUE_REQUIRED,
            'The geckodriver server URL',
            'http://localhost:4444'
        );

        $this->addOption(
            'execute',
            'x',
            InputOption::VALUE_NONE,
            'If provided, the accounts are filled up for real. Otherwise only information is displayed.'
        );

        $this->addOption(
            'exclude',
            'c',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'The given accounts are excluded from the filling up process.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $this->formatter = new NumberFormatter('de_DE', NumberFormatter::CURRENCY);

        try {
            $this->initDriver();

            $this->driver->get(self::URL_OVERVIEW);

            $session = $this->collectSessionData();

            $this->output->writeln('Go to source account dashboard...');
            $this->selectSourceAccount($session->getSourceAccount());

            $this->fillUpAccounts($session);

            $this->quit();
        } catch (Exception $e) {
            $this->quit();
            throw $e;
        }

        return self::SUCCESS;
    }

    private function collectSessionData(): Session
    {
        $sourceAccountName = $this->input->getArgument('source');
        $excludedAccountNames = array_map('strtolower', $this->input->getOption('exclude') ?? []);
        $sourceAccount = null;
        $targetAccounts = [];

        $tableRows = $this->driver->findElements(WebDriverBy::cssSelector('#dotqmailtable tbody tr'));

        $isFirst = true;

        foreach ($tableRows as $tableRow) {
            if ($isFirst) {
                $isFirst = false;
                continue;
            }

            $columns = $tableRow->findElements(WebDriverBy::cssSelector('td'));

            $amountInCents = $this->parseCurrency($columns[4]->getText());
            $priceInCents = $this->parseCurrency($columns[5]->getText());

            $tableRow = new Account(
                $columns[0]->getText(),
                $columns[1]->getText(),
                $amountInCents,
                $priceInCents
            );

            if (in_array(strtolower($tableRow->getName()), $excludedAccountNames, true)) {
                $this->output->writeln('Excluding account ' . $tableRow->getName());
                continue;
            }

            if ($tableRow->getName() === $sourceAccountName) {
                $sourceAccount = $tableRow;
                continue;
            }

            $targetAccounts[] = $tableRow;
        }

        Assert::notNull($sourceAccount, 'Source account could not be detected: ' . $sourceAccountName);
        Assert::notEmpty($targetAccounts, 'No target accounts could be detected.');

        $this->output->writeln(sprintf('Detected source account %s with balance of %s', $sourceAccount->getName(), $this->formatCurrency($sourceAccount->getCreditInCents())));
        $this->output->writeln('Detected ' . count($targetAccounts) . ' possible target accounts.');

        return new Session($sourceAccount, $targetAccounts);
    }

    private function fillUpAccount(Account $fillableAccount, int $amountToFillInCents): void
    {
        $this->driver->get(self::URL_DASHBOARD_BASE . '/accounting');
        $amountField = $this->driver->findElement(WebDriverBy::id('transfer_money'));
        $amountField->clear();
        $amountField->sendKeys((string)($amountToFillInCents / 100));

        $targetAccountField = $this->driver->findElement(WebDriverBy::id('uberspace_target'));
        $targetAccountField->clear();
        $targetAccountField->sendKeys($fillableAccount->getName());

        $this->driver
            ->wait()
            ->until(WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::id('umbuchen_button')));

        $transferButton = $this->driver->findElement(WebDriverBy::id('umbuchen_button'));
        $transferButton->click();

        $this->driver
            ->wait()
            ->until(
                WebDriverExpectedCondition::elementTextIs(
                    WebDriverBy::id('flash'),
                    'Wir haben den Betrag wunschgemäß umgebucht.'
                )
            );
    }

    private function fillUpAccounts(Session $session): void
    {
        $targetAmount = (float)$this->input->getArgument('amount');
        $targetAmountInCents = (int)($targetAmount * 100);

        foreach ($session->getFillableAccounts() as $fillableAccount) {
            $amountToFillInCents = $fillableAccount->getMissingAmount($targetAmountInCents);

            if ($amountToFillInCents === 0) {
                $this->output->writeln(
                    sprintf(
                        'Account %s does not need fillup, current amount: %s',
                        $fillableAccount->getName(),
                        $this->formatCurrency($fillableAccount->getCreditInCents())
                    )
                );
                continue;
            }

            $this->output->writeln(
                sprintf(
                    'Account %s needs fillup of %s, current amount: %s',
                    $fillableAccount->getName(),
                    $this->formatCurrency($amountToFillInCents),
                    $this->formatCurrency($fillableAccount->getCreditInCents())
                )
            );

            if ($this->input->getOption('execute')) {
                $this->fillUpAccount($fillableAccount, $amountToFillInCents);
            }
        }
    }

    private function formatCurrency(int $amountInCents): string
    {
        return $this->formatter->formatCurrency($amountInCents / 100, self::CURRENCY);
    }

    private function initDriver(): void
    {
        $this->driver = RemoteWebDriver::create($this->input->getOption('serverUrl'), DesiredCapabilities::firefox());
    }

    private function parseCurrency(string $formattedValue): int
    {
        $parsableValue = str_replace(' ', ' ', $formattedValue);
        $parsedAmount = $this->formatter->parseCurrency($parsableValue, $curr);
        Assert::float($parsedAmount, 'Scraped amount could not be parsed to float: ' . $formattedValue);
        return (int)($parsedAmount * 100);
    }

    private function quit(): void
    {
        if ($this->driver instanceof RemoteWebDriver) {
            $this->driver->quit();
        }
        $this->driver = null;
    }

    private function selectSourceAccount(Account $sourceAccount): void
    {
        $switchAccountUrl = sprintf(self::URL_OVERVIEW_SWITCH_ACCOUNT, $sourceAccount->getName());
        $this->driver->get($switchAccountUrl);
    }

    private function unfoldAccountsTable(): void
    {
        $switchAccountLink = $this->driver->findElement(WebDriverBy::cssSelector('.otheraccount > a:first-child'));
        $switchAccountLink->click();

        // Wait for slidedown to finish.
        sleep(1);
    }
}
