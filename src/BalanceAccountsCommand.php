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

    private const DASHBOARD_BASE_URL = 'https://dashboard.uberspace.de/dashboard';

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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;

        $this->formatter = new NumberFormatter('de_DE', NumberFormatter::CURRENCY);

        try {
            $this->initDriver();

            $this->driver->get(self::DASHBOARD_BASE_URL);
            $this->unfoldAccountsTable();

            $session = $this->collectSessionData();

            if (!$session->isSourceAccountSelected()) {
                $this->output->writeln('Switching to source account...');
                $session = $this->selectSourceAccount($session->getSourceAccount());
            }

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
        $sourceAccount = null;
        $selectedAccount = null;
        $targetAccounts = [];

        $accounts = $this->driver->findElements(WebDriverBy::cssSelector('#otheraccounttable tbody tr'));

        foreach ($accounts as $account) {
            $columns = $account->findElements(WebDriverBy::cssSelector('td'));

            $amountInCents = $this->parseCurrency($columns[2]->getText());
            $priceInCents = $this->parseCurrency($columns[3]->getText());

            $account = new Account(
                $columns[0]->getText(),
                $columns[1]->getText(),
                $amountInCents,
                $priceInCents
            );

            if (count($columns[0]->findElements(WebDriverBy::tagName('strong'))) === 1) {
                Assert::null($selectedAccount, 'Duplicated selected account detected!');
                $selectedAccount = $account;
            }

            if ($account->getName() === $sourceAccountName) {
                $sourceAccount = $account;
                continue;
            }

            $targetAccounts[] = $account;
        }

        Assert::notNull($sourceAccount, 'Source account could not be detected: ' . $sourceAccountName);
        Assert::notNull($selectedAccount, 'The selected account could not be detected.');
        Assert::notEmpty($targetAccounts, 'No target accounts could be detected.');

        $this->output->writeln('Detected source account ' . $sourceAccount->getName());
        $this->output->writeln('Detected selected account ' . $selectedAccount->getName());
        $this->output->writeln('Detected ' . count($targetAccounts) . ' possible target accounts.');

        return new Session($sourceAccount, $selectedAccount, $targetAccounts);
    }

    private function fillUpAccount(Account $fillableAccount, int $amountToFillInCents): void
    {
        $this->driver->get(self::DASHBOARD_BASE_URL . '/accounting');
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

    private function fillUpAccounts(Session $session)
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

    private function selectSourceAccount(Account $sourceAccount): Session
    {
        $this->driver->get(self::DASHBOARD_BASE_URL . '/switch_user?selected_account=' . $sourceAccount->getName());
        $this->unfoldAccountsTable();

        $updatedSession = $this->collectSessionData();

        Assert::eq($sourceAccount->getName(), $updatedSession->getSelectedAccount()->getName());

        return $updatedSession;
    }

    private function unfoldAccountsTable(): void
    {
        $switchAccountLink = $this->driver->findElement(WebDriverBy::cssSelector('.otheraccount > a:first-child'));
        $switchAccountLink->click();

        // Wait for slidedown to finish.
        sleep(1);
    }
}
