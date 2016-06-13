# Seltzer CRM Transaction module

This is a simple Transaction Module for Seltzer CRM. 
Keept track of income and expenses for your hackerspace.

Seltzer CRM project on github: https://github.com/elplatt/seltzer


## Installation

Create a directory named '''transaction''' under /crm/modules and place transaction.inc.php in that directory.

In '''config.inc.php''' add: $config_modules[] = "transaction";

and in '''$config_links''' array also add: , 'transactions' => 'Transactions'

From your admin panel, go to upgrade page on your menu, and upgrade your crm modules.


This module is compatible with Seltzer CRM 0.4.1.dev
