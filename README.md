TMGMT LangConnector (tmgmt_lang_connector)
---------------------

TMGMT LangConnector module is a plugin for Translation Management Tool module (tmgmt).
It uses the LangConnector API (https://www.lang_connector.com/en/docs-api/) for automated
translation of the content. You can use the LangConnector API Free (limited to 500.000 
characters per month) or the LangConnector API Pro for more than 500.000 characters.
More information on pricing can be found on https://www.lang_connector.com/pro#developer.

REQUIREMENTS
------------

This module requires TMGMT (http://drupal.org/project/tmgmt) module to be 
installed.

Also you will need to enter your LangConnector API authentification key. You can find 
them on the page https://www.lang_connector.com/pro#developer after registration on 
https://www.lang_connector.com.

CONFIGURATION
-------------

- add a new translation provider at /admin/tmgmt/translators
- choose between "LangConnector API Free" or "LangConnector API Pro" and enter your API key
- set additional settings related to the LangConnector API 

You can use LangConnector simulator to figure out the right LangConnector API plugin settings
for your provider: https://www.lang_connector.com/docs-api/simulator/
