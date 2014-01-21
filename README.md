Cpanel Whmcs Comparator
=======================

MadeIT.com cPanel and WHMCS accounting mapping reporter will show difference between accounts in WHMCS and Cpanel server. This report get all active cPanel servers in WHMCS servers list and compare account status in Cpanel with WHMCS product status.

Will report following mismatch in account statuses :

 1. username not found in Cpanel(probably deleted in Cpanel)
 2. Account in Cpanel is active but in WHMCS has different status than       active(Suspend, Fraud ...)
 3. Account in Cpanel is not active but active in WHMCS
 4. Owner(resseler) was deleted and some of her clients is active.

> If account exists in WHMCS than values in columns "Domain" and "Username" will be a valid links to WHMCS configure product page.
