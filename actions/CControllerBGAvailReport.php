<?php declare(strict_types = 1);

namespace Modules\LMFR\Actions;

use CController;
use CSettingsHelper;
use API;
use CArrayHelper;
use CUrl;
use CPagerHelper;
use CRangeTimeParser;
use CWebUser;

abstract class CControllerBGAvailReport extends CController {

	// Filter idx prefix.
	const FILTER_IDX = 'web.monitoring.problem';

	// Filter fields default values.
	const FILTER_FIELDS_DEFAULT = [
		'show' => TRIGGERS_OPTION_RECENT_PROBLEM,
		'groupids' => [],
		'hostids' => [],
		'triggerids' => [],
		'name' => '',
		'severities' => [],
		'age_state' => 0,
		'age' => 14,
		'inventory' => [],
		'evaltype' => TAG_EVAL_TYPE_AND_OR,
		'tags' => [],
		'show_tags' => SHOW_TAGS_3,
		'show_symptoms' => 0,
		'show_suppressed' => 0,
		'unacknowledged' => 0,
		'compact_view' => 0,
		'show_timeline' => ZBX_TIMELINE_ON,
		'details' => 0,
		'highlight_row' => 0,
		'show_opdata' => OPERATIONAL_DATA_SHOW_NONE,
		'tag_name_format' => TAG_NAME_FULL,
		'tag_priority' => '',
		'page' => null,
		'sort' => 'clock',
		'sortorder' => ZBX_SORT_DOWN,
		'from' => '',
		'to' => ''
	];

	/**
	 * Get count of resulting rows for specified filter.
	 *
	 * @param array $filter   Filter fields values.
	 *
	 * @return int
	 */
	// protected function getCount(array $filter): int {
	// 	$range_time_parser = new CRangeTimeParser();
	// 	$range_time_parser->parse($filter['from']);
	// 	$filter['from'] = $range_time_parser->getDateTime(true)->getTimestamp();
	// 	$range_time_parser->parse($filter['to']);
	// 	$filter['to'] = $range_time_parser->getDateTime(false)->getTimestamp();

	// 	$data = CScreenProblem::getData($filter, CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT));

	// 	return count($data['problems']);
	// }

	protected function getData(array $filter): array {

		$host_group_ids = sizeof($filter['hostgroupids']) > 0 ? $this->getChildGroups($filter['hostgroupids']) : null;

		$generating_csv_flag = 1;
		if (!array_key_exists('action_from_url', $filter) ||
			$filter['action_from_url'] != 'availreport.view.csv') {
			// Generating for UI
			// $limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT) + 1;
			$limit = CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT) + 130000;
			$generating_csv_flag = 0;
		} else {
			// Generating for CSV report
			$limit = 5001;
		}
		// All CONFIGURED triggers that fall under selected filter
		$num_of_triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'expression', 'value'],
			'monitored' => true,
			'triggerids' => sizeof($filter['triggerids']) > 0 ? $filter['triggerids'] : null,
			'groupids' => $host_group_ids,
			'hostids ' => sizeof($filter['hostids']) > 0 ? $filter['hostids'] : null,
			'filter' => [
				'templateid' => sizeof($filter['tpl_triggerids']) > 0 ? $filter['tpl_triggerids'] : null
			],
			'countOutput' => true
		]);
		$warning = null;
		if ($num_of_triggers > $limit) {
			$warning = 'WARNING: ' . $num_of_triggers . ' triggers found which is more than reasonable limit ' . $limit . ', results below might be not totally accurate. Please add or review current filter conditions.';
		}

		// Get timestamps from and to
		if ($filter['from'] != '' && $filter['to'] != '') {
			$range_time_parser = new CRangeTimeParser();
			$range_time_parser->parse($filter['from']);
			$filter['from_ts'] = $range_time_parser->getDateTime(true)->getTimestamp();
			$range_time_parser->parse($filter['to']);
			$filter['to_ts'] = $range_time_parser->getDateTime(false)->getTimestamp();
		} else {
			$filter['from_ts'] = null;
			$filter['to_ts'] = null;
		}


		// get 100 triggerids with max event count
		$triggersEventCount = [];
		$sql = 'SELECT e.objectid,count(distinct e.eventid) AS cnt_event'.
				' FROM triggers t,events e'.
				' WHERE t.triggerid=e.objectid'.
					' AND e.source='.EVENT_SOURCE_TRIGGERS.
					' AND e.value='.TRIGGER_VALUE_TRUE.
					' AND e.clock>='.zbx_dbstr($filter['from_ts']).
					' AND e.clock<='.zbx_dbstr($filter['to_ts']);
		
		$sql .= ' AND '.dbConditionInt('t.flags', [ZBX_FLAG_DISCOVERY_NORMAL, ZBX_FLAG_DISCOVERY_CREATED]).
				' GROUP BY e.objectid'.
				' ORDER BY cnt_event DESC';
		$result = DBselect($sql, 100);
		while ($row = DBfetch($result)) {
			$triggersEventCount[$row['objectid']] = $row['cnt_event'];
		}

		// retrieve only triggerid which is in the filter and exist in triggereventcount(filter + sql result) use it in trigger api call
		$matched_triggerids = array_intersect(array_keys($triggersEventCount), $filter['triggerids']);


		$triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'expression', 'value', 'priority', 'lastchange'],
			'selectHosts' => ['hostid', 'status', 'name'],
			'triggerids' => sizeof($filter['triggerids']) > 0 ? $matched_triggerids : array_keys($triggersEventCount), //added to get top 100 
			'selectTags' => 'extend',
			'selectFunctions' => 'extend',
			'expandDescription' => true,
			'monitored' => true,
			'groupids' => $host_group_ids,
			'preservekeys' => true,
			'hostids' => sizeof($filter['hostids']) > 0 ? $filter['hostids'] : null,
			'filter' => [
				'templateid' => sizeof($filter['tpl_triggerids']) > 0 ? $filter['tpl_triggerids'] : null,
			],
            'limit' => $limit
        ]);

		## added for cnt and hostid
		foreach ($triggers as $triggerId => $trigger) {
			$triggers[$triggerId]['cnt_event'] = $triggersEventCount[$triggerId];
		}

		// Now just prepare needed data. Modified to take 2 ways of sorts
		$filter['sortorder'] == 'ASC' ? CArrayHelper::sort($triggers, [
			['field' => 'cnt_event', 'order' => ZBX_SORT_UP],
			'host', 'description', 'priority'
		]): CArrayHelper::sort($triggers, [
			['field' => 'cnt_event', 'order' => ZBX_SORT_DOWN],
			'host', 'description', 'priority'
		]);

		$view_curl = (new CUrl())->setArgument('action', 'availreport.view');

		$rows_per_page = (int) CWebUser::$data['rows_per_page'];
        $selected_triggers = [];
		$i = 0;  // Counter. We need to stop doing expensive calculateAvailability() when results are not visible
		$page = $filter['page'] ? $filter['page'] - 1 : 0;
		$start_idx = $page * $rows_per_page;
		$end_idx = $start_idx + $rows_per_page;
		foreach ($triggers as &$trigger) {
			if ($generating_csv_flag ||
				 ($i >= $start_idx && $i < $end_idx) ) {
				$trigger['availability'] = calculateAvailability($trigger['triggerid'], $filter['from_ts'], $filter['to_ts']);
				// if ($filter['only_with_problems']) {
				if ($trigger['availability']['true'] > 0.00005) {
					$selected_triggers[] = $trigger;
				}
				// } 
				else {
					$selected_triggers[] = $trigger;
				}
			} 
			else {
				$selected_triggers[] = $trigger;
			}
			$i++;
		}

		if (!$generating_csv_flag) {
			// Not exporting data to CSV, just showing the data
			// Split result array and create paging. Only if not generating CSV.
			$paging = CPagerHelper::paginate($filter['page'], $selected_triggers, 'ASC', $view_curl);
		}

		foreach ($selected_triggers as &$trigger) {
			$trigger['host_name'] = $trigger['hosts'][0]['name'];
		}
		unset($trigger);

		return [
			'paging' => $paging,
			'triggers' => $selected_triggers,
			'warning' => $warning
		];
	}

	/**
	 * Get additional data required for render filter as HTML.
	 *
	 * @param array $filter  Filter fields values.
	 *
	 * @return array
	 */
	protected function getAdditionalData(array $filter): array {
		$data = [
			'groups' => [],
			'hosts' => [],
			'triggers' => []
		];

		// Host groups multiselect.
		if ($filter['groupids']) {
			$host_groups = API::HostGroup()->get([
				'output' => ['groupid', 'name'],
				'groupids' => $filter['groupids'],
				'preservekeys' => true
			]);
			$data['groups'] = CArrayHelper::renameObjectsKeys($host_groups, ['groupid' => 'id']);
		}

		// Triggers multiselect.
		if ($filter['triggerids']) {
			$triggers = CArrayHelper::renameObjectsKeys(API::Trigger()->get([
				'output' => ['triggerid', 'description'],
				'selectHosts' => ['name'],
				'expandDescription' => true,
				'triggerids' => $filter['triggerids'],
				'monitored' => true
			]), ['triggerid' => 'id', 'description' => 'name']);

			CArrayHelper::sort($triggers, [
				['field' => 'name', 'order' => ZBX_SORT_UP]
			]);

			foreach ($triggers as &$trigger) {
				$trigger['prefix'] = $trigger['hosts'][0]['name'].NAME_DELIMITER;
				unset($trigger['hosts']);
			}
			unset($trigger);

			$data['triggers'] = $triggers;
		}

		// Hosts multiselect.
		if ($filter['hostids']) {
			$data['hosts'] = CArrayHelper::renameObjectsKeys(API::Host()->get([
				'output' => ['hostid', 'name'],
				'hostids' => $filter['hostids']
			]), ['hostid' => 'id']);
		}

		return $data;
	}

	/**
	 * Clean and convert passed filter input fields from default values required for HTML presentation.
	 *
	 * @param array $input  Filter fields values.
	 *
	 * @return array
	 */
	protected function cleanInput(array $input): array {
		if (array_key_exists('tags', $input) && $input['tags']) {
			$input['tags'] = array_filter($input['tags'], function($tag) {
				return !($tag['tag'] === '' && $tag['value'] === '');
			});
			$input['tags'] = array_values($input['tags']);
		}

		if (array_key_exists('inventory', $input) && $input['inventory']) {
			$input['inventory'] = array_filter($input['inventory'], function($inventory) {
				return $inventory['value'] !== '';
			});
			$input['inventory'] = array_values($input['inventory']);
		}

		return $input;
	}

	/**
	 * Validate input of filter inventory fields.
	 *
	 * @return bool
	 */
	protected function validateInventory(): bool {
		if (!$this->hasInput('inventory')) {
			return true;
		}

		$ret = true;
		foreach ($this->getInput('inventory') as $filter_inventory) {
			if (count($filter_inventory) != 2
					|| !array_key_exists('field', $filter_inventory) || !is_string($filter_inventory['field'])
					|| !array_key_exists('value', $filter_inventory) || !is_string($filter_inventory['value'])) {
				$ret = false;
				break;
			}
		}

		return $ret;
	}

	/**
	 * Validate values of filter tags input fields.
	 *
	 * @return bool
	 */
	protected function validateTags(): bool {
		if (!$this->hasInput('tags')) {
			return true;
		}

		$ret = true;
		foreach ($this->getInput('tags') as $filter_tag) {
			if (count($filter_tag) != 3
					|| !array_key_exists('tag', $filter_tag) || !is_string($filter_tag['tag'])
					|| !array_key_exists('value', $filter_tag) || !is_string($filter_tag['value'])
					|| !array_key_exists('operator', $filter_tag) || !is_string($filter_tag['operator'])) {
				$ret = false;
				break;
			}
		}

		return $ret;
	}
}
