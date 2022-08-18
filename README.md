# Uberspace credit distribution

Evenly distribute the account credit of your [Uberspace](https://uberspace.de) accounts from
a source account.

## Warning

> **Warning**
> Uberspace currently seems to restructure / translate their dashboard. Therefore 
> It might be possible this script does not work any more.
> Please create an issue when you experience problems.

## Example:

* `uber1`, credit: 10 €
* `uber2`, credit 50 € (source accunt)
* `uber3`, credit 12 €

After running this script, both target accounts will have a credit of 15 €:

```bash
php bin/console uberspace:balanceaccount uber2 15 -x
```

* 5 € will be transferred from `uber2` to `uber1`
* 3 € will be transferred from `uber2` to `uber3`

## How To

### Log in

This Howto is based on Firefox but should work similar with Chrome based
browsers.

First, start Firefox with enabled remote control server:

```bash
firefox --marionette
```

Now log on to the [Userspace Dashboard](https://dashboard.uberspace.de) with your OpenId.
You should be able to switch to all the accounts that you want to fill up and to
the source account.

### Run geckodriver

Download and extract [geckodriver](https://github.com/mozilla/geckodriver). Tell it
to connect to your running Firefox instance:

```bash
geckodriver --connect-existing --marionette-port 2828
```

You can find out the port by visiting [about:config](about:config) and searching for
`marionette.port`.

### Install and run this script

Finally download and extract this script an run it (required PHP 8.1+ and Composer installed):

```bash
composer install
./bin/console <sourceAccount> <amountAsFloat>
```

This will only show you what it would do (dry run). If everything is OK, you can
execute the fillup process by passing the `-x` Parameter:

```bash
./bin/console <sourceAccount> <amountAsFloat> -x
```
