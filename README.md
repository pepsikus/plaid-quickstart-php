# Plaid quickstart PHP
 
Simple PHP application for [plaid.com][1] API testing.

This is a PHP & [Slim][2] port of the official [Quickstart][3] application.


## Table of Contents

- [Install](#install)
- [Documentation](#documentation)
- [Examples](#examples)

## Install
```console
$ composer require pepsikus/plaid-quickstart-php
```

## Documentation
This app uses the [Plaid-api-php-client][4] library.

Additionally to official Quickstart capabilities this app allows you to testing the ['Rotate Access Token'][5] endpoint.

For complete information about the Plaid.com API, head to the [Plaid Documentation][6].

## Examples
Run app with your API keys using internal PHP server
```console
$ cd public
$ PLAID_CLIENT_ID='CLIENT_ID' \
PLAID_SECRET='SECRET' \
PLAID_PUBLIC_KEY='PUBLIC_KEY' \
PLAID_ENV='sandbox' \
php -S localhost:8000
```

Go to http://localhost:8000


## License
[MIT][7]

[1]: https://plaid.com
[2]: https://slimframework.com
[3]: https://github.com/plaid/quickstart
[4]: https://github.com/dpods/plaid-api-php-client
[5]: https://plaid.com/docs/#rotate-access-token
[6]: https://plaid.com/docs/api
[7]: https://github.com/pepsikus/plaid-quickstart-php/blob/master/LICENSE