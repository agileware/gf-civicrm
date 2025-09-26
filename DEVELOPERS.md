# Experimental feature

This experimental feature is to implement remote CiviCRM support via the CiviMcRestFace framework. 
Use of this version of the gf-civicrm plugin is not yet recommended and impossible for non-developers.

# Remote CiviCRM Integration through CiviMcRestFace

## Installation

1. Download and install this release of GF CiviCRM.
1. Download the latest version of CiviMcRestFace from [GitHub](https://github.com/CiviMRF/civimcrestface-wordpress/tree/master) or [WordPress](https://wordpress.org/plugins/connector-civicrm-mcrestface/). Add this to your plugins directory. Make sure the plugin directory name is `connector-civicrm-mcrestface`.
1. RECOMMENDED: In `connector-civicrm-mcrestface/composer.json`, set `"civimrf/cmrf_abstract_core"` to the latest version. (Minimum 0.10.4)
1. Open a terminal to your WordPress install. Go to the `connector-civicrm-mcrestface` directory. Run `composer install`.
1. Activate GF CiviCRM and Connector to CiviCRM with CiviMcRestFace (`connector-civicrm-mcrestface`).
1. Go to **Settings -> CiviCRM McRestFace Connections**
1. Add a new connection profile to your remote CiviCRM Installation. CMRF will validate your connection.
1. Go to **Forms -> Settings** and open the **CiviCRM Settings** subview.
1. Under **CiviCRM REST Connection Profile**, select your connection profile. **NOTE:** By default if no connection profile is selected, GF CiviCRM will attempt to find a local installation.
1. When you select a connection profile, a series of **preflight checks** will run to confirm a baseline connection to CiviCRM. This will help identify if a connection can be established and if the nominated API user who owns the API key provided has sufficient permissions for core CiviCRM API calls used by this plugin.

## Performing API calls

Pass your API arguments through `api_wrapper()`. By default this will run an API v3 query. Specify version 4 to run API v4.

See `gf-civicrm-wpcmrf.php`.

### API v3 example

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

### API v4 example

Easiest way to build the parameters is to use the API Explorer v4, and copy the Traditional style PHP arguments.

```
// Join API call to get payment tokens.
$api_params = [
    'select' => [
        'id', 'masked_account_number', 'expiry_date', 'token',
        'contribution_recur.contribution_status_id:label',
        'contribution_recur.amount',
        'contribution_recur.currency:abbr',
        'contribution_recur.frequency_unit:label',
        'contribution_recur.frequency_interval',
        'contribution_recur.financial_type_id:label',
        'contribution_recur.id',
        'membership.membership_type_id:label',
        'contribution.source',
        'contribution.contribution_page_id:label'
    ],
    'join' => [
        ['ContributionRecur AS contribution_recur', 'LEFT', ['contribution_recur.payment_token_id', '=', 'id']],
        ['Contribution AS contribution', 'LEFT', ['contribution.contribution_recur_id', '=', 'contribution_recur.id']],
    ],
    'where' => [
        ['contribution_recur.contribution_status_id', 'IN', [5, 2, 8, 7]],
        ['payment_processor_id', '=', 4],
        ['contact_id', '=', 'user_contact_id']
    ],
    'orderBy' => [
        'expiry_date' => 'DESC',
        'contribution_recur.id' => 'DESC',
    ]
];

$payment_tokens = api_wrapper($profile_name, 'PaymentToken', 'get', $api_params, [], 4);

```