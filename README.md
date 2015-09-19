# dyndns-update
PHP DynDns update script for bind dns server

## Requirements
- PHP5 with mysqli
- Mysql Server
- configured Bind DNS Server
- Tested on FreeBSD, can be modified for Linux easily

## Setup
- Create a database account
- Fill the missing data at the top of the script
- Call the script with ?setup to create database table and procedure
- in mysql use 'call create_account('dyndns.example.com', 'passwd');' to create an account

## Usage
- Call script with get or post params domain, password and at least one of ipv4, ipv6, ip (where ip can be both types)
