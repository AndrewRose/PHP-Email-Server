PES - Personal EMail Services
=======

Test environment/Requirements: PHP 7.x.x, Linux, MySQL or MariaDB

This package is currently still in development but is working to a degree.

PES's aim is to be a personal mail server that is quick and easy to deploy.  It includes an SMTP MDA and IMAP server and is being written in plain PHP with no external dependencies apart from a handful of PHP extensions; posix, pecl event, sockets, pdo, pdo_mysql, imap, pcntl, openssl and mailparse.

Setup a domain:
* Register a domain name
* Setup an MX record to point to your servers IP

Setup certs:
* certbot certonly --standalone -d test.com

To setup imapd:

* configure conf/imapd.ini
* create a new mysql database called pes
* mysql pes < conf/pes.sql
* cd tools && ./email -c add -e user@test.com -p password -n Real Name

To run imapd:

* cd bin
* ./imapd

To run smtpd:

* cd bin
* ./smtpd

To stop imapd:

* killall imapd

To stop smtpd:

* killall imapd

Mail Client setup:
For testing I've been using Thunderbird and Opera Mail.  Both should manage to automatically detect and configure themselves to use STARTTLS.

Debugging:

Make sure there are no echo/print_r as anything that sends output to STDOUT as this will cause PHP to bail when being run as a detached from console deamon in the background as it attempts to write to a non existant console when you've logged out.
ignore_user_abort(true);

When testing starttls using the open ssl client, be sure to use -crlf and be aware that 'RCPT TO' or any command starting with an upper case R will be captured by openssl to renogotiate..

smtpd: openssl s_client -connect localhost:25 -starttls smtp -crlf
imapd: openssl s_client -connect localhost:143 -starttls imap -crlf

strace example:
ps aux | grep smtpd
get the process pid
strace -p <pid> &> /tmp/smtpd.strace &

Notes:
Attempts were made to allow for low max_packet_size however MySQL takes max_packet_size into account when using CONCAT and applies the limits to fields that are being selected NOT just data
being sent on the wire, which is nice and confusing.. see bug (not-a-bug): https://bugs.mysql.com/bug.php?id=20458

The MySQL PDO backend is now setup to require max_packet_size to be at least maxMailSize + 10% for overhead.
