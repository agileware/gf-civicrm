<?php

/**
 * Refs gf-address-enhanced
 */

if (!defined('ABSPATH')) {
	exit;
}
?>
<script type="text/html" id="tmpl-gf-civicrm-state-any">
	<input type="text" name="{{data.field_name}}" id="{{data.field_id}}" value="{{data.state}}" placeholder="{{data.placeholder}}"
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

<script type="text/html" id="tmpl-gf-civicrm-state-list-grouped">
	<select name="{{data.field_name}}" id="{{data.field_id}}"
		<# if (data.autocomplete) { #> autocomplete="{{data.autocomplete}}" <# } #>
		<# if (data.required) { #> aria-required="{{data.required}}" <# } #>
		<# if (data.describedby) { #> aria-describedby="{{data.describedby}}" <# } #>
		<# if (data.tabindex) { #> tabindex="{{data.tabindex}}" <# } #> >

		<option value="">{{data.placeholder}}</option>
		<# _.each(data.groups, function(g) { #>
		<optgroup label="{{g.name}}">
			<# _.each(g.states, function(s) { #>
			<option value="{{s[0]}}"<# if (data.state === s[0] || data.state === s[1]) { #> selected <# } #>>{{s[1]}}</option>
			<# }) #>
		</optgroup>
		<# }) #>
	</select>
</script>
