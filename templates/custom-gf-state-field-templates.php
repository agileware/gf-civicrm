<?php

/**
 * Copyright (c) 2018-2023 WebAware Pty Ltd (email : support@webaware.com.au)
 * 
 * This plugin is based on the original work by WebAware.
 * The original plugin can be found at: https://gf-address-enhanced.webaware.net.au/
 * Original License: GPLv2 or later
 * Original License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
	exit;
}
?>
<script type="text/html" id="tmpl-gf-civicrm-state-any">
	<input type="hidden" name="{{data.field_name}}" id="{{data.field_id}}" value="{{data.state}}" placeholder="{{data.placeholder}}"
		<# if (data.autocomplete) { #> autocomplete="{{data.autocomplete}}" <# } #>
		<# if (data.required) { #> aria-required="{{data.required}}" <# } #>
		<# if (data.describedby) { #> aria-describedby="{{data.describedby}}" <# } #>
		<# if (data.tabindex) { #> tabindex="{{data.tabindex}}" <# } #> />
</script>

<script type="text/html" id="tmpl-gf-civicrm-state-list">
	<select name="{{data.field_name}}" id="{{data.field_id}}"
		<# if (data.autocomplete) { #> autocomplete="{{data.autocomplete}}" <# } #>
		<# if (data.required) { #> aria-required="{{data.required}}" <# } #>
		<# if (data.describedby) { #> aria-describedby="{{data.describedby}}" <# } #>
		<# if (data.tabindex) { #> tabindex="{{data.tabindex}}" <# } #> >

		<option value="">{{data.placeholder}}</option>
		<# _.each(data.states, function(s) { #>
		<option value="{{s[0]}}"<# if (data.state === s[0] || data.state === s[1]) { #> selected <# } #>>{{s[1]}}</option>
		<# }) #>
	</select>
</script>
