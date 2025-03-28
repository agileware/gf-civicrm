# Experimental feature

This experimental feature is to implement remote CiviCRM support via the CiviMcRestFace framework. 
Use of this version of the gf-civicrm plugin is not yet recommended and impossible for non-developers.

# Remote CivicRM Integration through CiviMcRestFace

## Installation

1. Download and install this release of GF CiviCRM.
1. Download the latest version of CiviMcRestFace from [GitHub](https://github.com/CiviMRF/civimcrestface-wordpress/tree/master) or [WordPress](https://wordpress.org/plugins/connector-civicrm-mcrestface/). Add this to your plugins directory. Make sure the plugin directory name is `connector-civicrm-mcrestface`.
1. RECOMMENDED: In `connector-civicrm-mcrestface/composer.json`, set `"civimrf/cmrf_abstract_core"` to the latest version. (Minimum 0.10.4)
1. Open a terminal to your WordPress install. Go to the `connector-civicrm-mcrestface` directory. Run `composer install`.
1. Activate GF CiviCRM and Connector to CiviCRM with CiviMcRestFace (`connector-civicrm-mcrestface`).
1. Go to **Settings -> CiviCRM McRestFace Connections**
1. Add a new connection profile to your remote CiviCRM Installation. CMRF will validate your connection.
1. Go to **Forms -> Settings** and open the **CiviCRM Settings** subview.
1. Under CiviCRM REST Connection Profile, select your connection profile. **NOTE:** By default if no connection profile is selected, GF CiviCRM will attempt to find a local installation.

## Performing API calls

Pass your API arguments through `api_wrapper()`. See `gf-civicrm-wpcmrf.php`.

For example:

```
// Chained API call to get an option group by the name, and all the options associated.
$entity = 'OptionGroup';
$action = 'getsingle';
$api_params = [
    'sequential' => 1,
    'return' => ["id", "name"],
    'name'		=> $option_group,
    'is_active' => 1,
    'api.OptionValue.get' => [
        'return' => ["id", "label", "value", "is_default"], 
        'is_active' => 1, 
        'sort' => "weight ASC"
    ],
];
$api_options = [
    'limit' => 1
];
$option_group_data = api_wrapper( $profile_name, $entity, $action, $api_params, $api_options );

```

### Limitations

WPCMRF currently only supports APIv3 calls, as per CMRF_Abstract_Core. This is marked to change in the next major release.



