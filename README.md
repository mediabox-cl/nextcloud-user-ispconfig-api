# Nextcloud - User ISPConfig API

This Backend allows users to sign in with their email or custom login name using the **ISPConfig 3** Control Panel API.  

**IMPORTANT:** This APP requires the installation of a plugin in the **ISPConfig 3** Control Panel. [ISPConfig - Nextcloud Plugin](https://github.com/mediabox-cl/ispconfig-nextcloud-plugin.git)

## Features

### Users

- Users sign in to Nextcloud can be restricted by Domain or by User.
- Users can sign in using an email or custom login name created in **ISPConfig 3**.
- Auto create the **Nextcloud** user account.
- Set the user email as system email in **Nextcloud**.
- Set the user quota defined by User or Domain (fallback).
- Users can change their **ISPConfig** password and display name in the **Nextcloud** interface.
- ...

### Groups

- Crete the Server, Domain and User Groups.
- Add / Remove users from groups.
- Make user admin of the Server, Domain and User Group.
- Auto delete empty groups created by this App.
- ...

### Federated Cloud ID

For a user with this email `user@happy.tld` and **Nextcloud** running in the subdomain `cloud.domain.tld`, the **Federated Cloud ID** will have this format:
`user.happy.tld@cloud.domain.tld` (This can't be changed)

## Installation

### Automatic installation (recommended)

~~Just install it from your Nextcloud application catalogue.~~ Not available (yet)

### Manual installation

Clone this repository into your **Nextcloud** apps directory:

```bash
cd /var/www/nextcloud/site/apps/
sudo -u www-data git clone https://github.com/mediabox-cl/nextcloud-user-ispconfig-api.git user_ispconfig_api
```
Install it as usual from admin app list or CLI with:

```bash
cd ..
sudo -u www-data php occ app:install user_ispconfig_api
sudo -u www-data php occ app:enable user_ispconfig_api
```

## Update

### Manual Update

Update the cloned repository in your **Nextcloud** apps directory:

```bash
cd /var/www/nextcloud/site/apps/user_ispconfig_api
sudo -u www-data git pull
```

Update the **Nextcloud** APP:

```bash
cd /var/www/nextcloud/site
sudo -u www-data php occ upgrade
```

## Configuration

### Prerequisites

This backend uses the **ISPConfig 3** SOAP API. Thus, it requires credentials for a legitimate remote API user.

In your **ISPConfig 3** control panel go to `System > Remote Users` and create a new user
with permissions for `Server functions`, `Mail domain functions` and `Mail user functions`.

_Note: I recommend to restrict the allowed client IP to the **Nextcloud** server IP._

Along with that, you have to provide the SOAP API Location and Uri.  
If you didn't modify it, these should be:

- Location: `https://host.domain.tld:8080/remote/index.php`
- Uri: `https://host.domain.tld:8080/remote/`

To finally enable authentication against the **ISPConfig 3** API, you need to add it
 to your **Nextcloud** config file in `config/config.php`.
Using this basic configuration will allow any mail user to authenticate with
their email address or custom login name and password and will create a new **Nextcloud** account on first login.

```php
<?php
$CONFIG = array(
//  [ ... ],
    'user_ispconfig_api' => array(
        'location' => 'https://host.domain.tld:8080/remote/index.php',
        'uri' => 'https://host.domain.tld:8080/remote/',
        'user' => 'remote_user',
        'password' => 'secure_remote_user_password',
    ),
);
```
### What's next?

Now you must follow the instruction to install the [ISPConfig - Nextcloud Plugin](https://github.com/mediabox-cl/ispconfig-nextcloud-plugin.git)

## Troubleshooting

### Always get 'Invalid Password'

Ensure you have the `PHP SOAP` extension installed and activated.

- Check your **Nextcloud** log messages for `ERROR: PHP soap extension is not installed or not enabled`  
- Check if you have the `php-soap` extension installed:

```bash
php -m | grep soap
```

The `soap` output indicates SOAP is installed, if not:

```bash
sudo apt update && sudo apt install php-soap -y
```

For **Apache**, enable the module and restart the service and for **PHP FPM** just restart the service.

## Thanks to:

- Michael Fürmann for the idea and code base.
- Till Brehm from Projektfarm GmbH.
- Falko Timme from Timme Hosting.
- The ISPConfig community and developers.
- The Nextcloud community and developers.
