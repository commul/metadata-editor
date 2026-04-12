<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Indicator DSD: time period formats and SDMX FREQ codes (code + label).
 *
 * - Each `code` under dsd_time_period_formats must match indicator_dsd.time_period_format and timeseries JSON schema enums.
 * - dsd_default_freq_by_time_period_format: default FREQ code for _ts_freq when no user FREQ column (promote / FastAPI).
 *
 * Load: $this->config->load('indicator_dsd', true);
 *       $this->config->item('dsd_time_period_formats', 'indicator_dsd');
 *       $this->config->item('dsd_freq_codes', 'indicator_dsd');
 *       $this->config->item('dsd_default_freq_by_time_period_format', 'indicator_dsd');
 */

$config['dsd_time_period_formats'] = array(
	array('code' => 'YYYY', 'label' => 'Year (YYYY)'),
	array('code' => 'YYYY-MM', 'label' => 'Year-month (YYYY-MM)'),
	array('code' => 'YYYY-MM-DD', 'label' => 'Date (YYYY-MM-DD)'),
	array('code' => 'YYYY-MM-DDTHH:MM:SS', 'label' => 'Date-time local (ISO 8601 without timezone)'),
	array('code' => 'YYYY-MM-DDTHH:MM:SSZ', 'label' => 'Date-time UTC (Z)'),
);

$config['dsd_freq_codes'] = array(
	array('code' => 'A', 'label' => 'Annual'),
	array('code' => 'A2', 'label' => 'Biennial'),
	array('code' => 'A3', 'label' => 'Triennial'),
	array('code' => 'A4', 'label' => 'Quadrennial'),
	array('code' => 'A5', 'label' => 'Quinquennial'),
	array('code' => 'A10', 'label' => 'Decennial'),
	array('code' => 'A20', 'label' => 'Bidecennial'),
	array('code' => 'A30', 'label' => 'Tridecennial'),
	array('code' => 'A_3', 'label' => 'Three times a year'),
	array('code' => 'S', 'label' => 'Half-yearly, semester'),
	array('code' => 'Q', 'label' => 'Quarterly'),
	array('code' => 'M', 'label' => 'Monthly'),
	array('code' => 'M2', 'label' => 'Bimonthly'),
	array('code' => 'M_2', 'label' => 'Semimonthly'),
	array('code' => 'M_3', 'label' => 'Three times a month'),
	array('code' => 'W', 'label' => 'Weekly'),
	array('code' => 'W2', 'label' => 'Biweekly'),
	array('code' => 'W3', 'label' => 'Triweekly'),
	array('code' => 'W4', 'label' => 'Four-weekly'),
	array('code' => 'W_2', 'label' => 'Semiweekly'),
	array('code' => 'W_3', 'label' => 'Three times a week'),
	array('code' => 'D', 'label' => 'Daily'),
	array('code' => 'D_2', 'label' => 'Twice a day'),
	array('code' => 'H', 'label' => 'Hourly'),
	array('code' => 'H2', 'label' => 'Bihourly'),
	array('code' => 'H3', 'label' => 'Trihourly'),
	array('code' => 'B', 'label' => 'Daily – business week'),
	array('code' => 'N', 'label' => 'Minutely'),
	array('code' => 'I', 'label' => 'Irregular'),
	array('code' => 'OA', 'label' => 'Occasional annual'),
	array('code' => 'OM', 'label' => 'Occasional monthly'),
	array('code' => '_O', 'label' => 'Other'),
	array('code' => '_U', 'label' => 'Unspecified'),
	array('code' => '_Z', 'label' => 'Not applicable'),
);

/**
 * Keys must match codes in dsd_time_period_formats; values must exist in dsd_freq_codes code list.
 */
$config['dsd_default_freq_by_time_period_format'] = array(
	'YYYY' => 'A',
	'YYYY-MM' => 'M',
	'YYYY-MM-DD' => 'D',
	'YYYY-MM-DDTHH:MM:SS' => 'D',
	'YYYY-MM-DDTHH:MM:SSZ' => 'D',
);
