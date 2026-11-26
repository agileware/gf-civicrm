# Importing and Exporting CiviCRM Integrated Gravity Forms

Gravity Forms with Webhook Feeds that call CiviCRM Form Processors can be now be exported onto your file system all at once.

To configure the base Import/Export Directory,

1. Go to Forms > Settings. Open the CiviCRM subview.
1. Edit the Import/Export Directory setting. This is relative to the root directory.

To export your Forms, Feeds, and Form Processors,

1. Go to Forms > Import/Export. Open the Export GF CiviCRM subview.
1. Select the Forms you wish to export.
1. Click on the "Export Selected" button to begin the export. This will export the Form, related Feeds, and related Form Processors to the Import/Export Directory.

These exported files can then be imported from the Import/Export Directory. **CAUTION:** This will overwrite any existing data that you import into. Take a backup before doing an import.

1. Go to Forms > Import/Export. Open the Import GF CiviCRM subview.
1. Select the Forms you wish to import. This will include related Feeds.
    - You can select an existing form to import into. **CAUTION:** This will overwrite all the data on the existing form. Take a backup before doing this.
    - You can choose to create a new form from the import file.
1. Select the Form Processors you wish to import. **CAUTION:** If a form processor exists with the same name on your system, it will be overwritten by the import file on import. Take a backup before doing this.
1. Click on the "Import Selected" button to begin the import. This will import the selected Forms and their related Feeds, and selected Form Processors.

## Protecting the Import/Export Directory

Exported files can contain feed and form processor settings, so the Import/Export Directory must not be publicly readable. The plugin writes an `.htaccess` (Apache) and an `index.php` into the base directory and each export subdirectory. **nginx ignores `.htaccess`**, as does Apache when `AllowOverride` is disabled, so on those servers add a rule to your server configuration. For nginx, in the site's `server` block and above any generic static file or `.json` location rules, using the path from your Import/Export Directory setting:

```nginx
location ~* ^/CRM/gf-civicrm-exports/ {
    deny all;
    return 404;
}
```

Reload nginx afterwards. The Import GF CiviCRM and Export GF CiviCRM screens check the directory over HTTP and show a warning if it is still publicly readable. The result is cached for up to an hour.
