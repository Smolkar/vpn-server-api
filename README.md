# Introduction
This service runs on the OpenVPN instances to control their behavior. It will
implement the following API calls:

Implemented:
* retrieve a list of connected clients
* disconnect a connected client
* trigger CRL refresh

TODO:
* prevent clients from connecting (temporary block)
* unprevent clients from connecting (temporary block)

# Configuration
To generate a password for `config/config.ini`, use this and replace `s3cr3t` 
with your password:

    php -r "require_once 'vendor/autoload.php'; echo password_hash('s3cr3t', PASSWORD_DEFAULT) . PHP_EOL;"

# Run

    php -S localhost:8080 -t web/

# API

## Status
List all connected clients:

    $ curl -u admin:s3cr3t http://localhost/vpn-server-api/api.php/status

    {
        "items": [
            {
                "bytes_received": 15937,
                "bytes_sent": 16999,
                "client_ip": "1.2.3.4",
                "common_name": "fkooman_five",
                "connected_since": 1449676274,
                "socket_id": "tcp://localhost:7506",
                "vpn_ip": [
                    "fd00:4343:4343::1000",
                    "10.43.43.2"
                ]
            }
        ]
    }

## Disconnect
Disconnect a currently connected client:

    $ curl -u admin:s3cr3t -d 'socket_id=tcp://localhost:7506&common_name=fkooman_five' http://localhost/vpn-server-api/api.php/disconnect

You have to specify the `socket_id` and `common_name` of the client in the POST
body.

## Refresh CRL
Trigger the reload of the CRL at the OpenVPN server:

    $ curl -u admin:s3cr3t -X POST http://localhost/vpn-server-api/api.php/refreshCrl

# License
Licensed under the Apache License, Version 2.0;

   http://www.apache.org/licenses/LICENSE-2.0
