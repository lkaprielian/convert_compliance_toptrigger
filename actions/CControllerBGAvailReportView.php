<?php declare(strict_types = 1);

namespace Modules\LMFR\Actions;

use CRoleHelper;
use CControllerResponseData;
use CControllerResponseFatal;
use CTabFilterProfile;
use CUrl;
use CWebUser;

class CControllerBGAvailReportView extends CControllerBGAvailReport {

	protected function init() {
		$this->disableCsrfValidation();
	}

	protected function checkInput() {
		$fields = [
			'name' =>			'string',
			// 'mode' =>			'in '.AVAILABILITY_REPORT_BY_HOST.','.AVAILABILITY_REPORT_BY_TEMPLATE,
			// 'tpl_groupids' =>		'array_id',
			// 'templateids' =>		'array_id',
			// 'tpl_triggerids' =>		'array_id',
			'triggerids' =>			'array_id',
			'groupids' =>		'array_id',
			'hostids' =>			'array_id',
			'filter_reset' =>		'in 1',
			'only_with_problems' =>		'in 0,1',
			'page' =>			'ge 1',
			'counter_index' =>		'ge 0',
			'sort' =>					'in clock,host,severity,name',
			'sortorder' =>				'in '.ZBX_SORT_DOWN.','.ZBX_SORT_UP,	
			'from' =>			'range_time',
			'to' =>				'range_time',
			'filter_name' =>			'string',
			'filter_custom_time' =>		'in 1,0',
			'filter_show_counter' =>	'in 1,0',
			'filter_counters' =>		'in 1',
			'filter_set' =>				'in 1',
			'show' =>					'in '.TRIGGERS_OPTION_RECENT_PROBLEM.','.TRIGGERS_OPTION_IN_PROBLEM.','.TRIGGERS_OPTION_ALL,
			'severities' =>				'array',
			'age_state' =>				'in 0,1',
			'age' =>					'int32',
			'show_symptoms' =>			'in 0,1',
			'show_suppressed' =>		'in 0,1',
			'unacknowledged' =>			'in 0,1',
			'inventory' =>				'array',
			'evaltype' =>				'in '.TAG_EVAL_TYPE_AND_OR.','.TAG_EVAL_TYPE_OR,
			'tags' =>					'array',
			'show_tags' =>				'in '.SHOW_TAGS_NONE.','.SHOW_TAGS_1.','.SHOW_TAGS_2.','.SHOW_TAGS_3,
			'compact_view' =>			'in 0,1',
			'show_timeline' =>			'in '.ZBX_TIMELINE_OFF.','.ZBX_TIMELINE_ON,
			'details' =>				'in 0,1',
			'highlight_row' =>			'in 0,1',
			'show_opdata' =>			'in '.OPERATIONAL_DATA_SHOW_NONE.','.OPERATIONAL_DATA_SHOW_SEPARATELY.','.OPERATIONAL_DATA_SHOW_WITH_PROBLEM,
			'tag_name_format' =>		'in '.TAG_NAME_FULL.','.TAG_NAME_SHORTENED.','.TAG_NAME_NONE,
			'tag_priority' =>			'string'
		];
		$ret = $this->validateInput($fields) && $this->validateTimeSelectorPeriod();

		if (!$ret) {
			$this->setResponse(new CControllerResponseFatal());
		}

		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction() {

		$filter_tabs = [];
		$profile = (new CTabFilterProfile(static::FILTER_IDX, static::FILTER_FIELDS_DEFAULT))->read();
		if ($this->hasInput('filter_reset')) {
			$profile->reset();
		}
		elseif ($this->hasInput('filter_set')) {
			$profile->setTabFilter(0, ['filter_name' => ''] + $this->cleanInput($this->getInputAll()));
			$profile->update();
		}
		else {
			$profile->setInput($this->cleanInput($this->getInputAll()));
		}


		foreach ($profile->getTabsWithDefaults() as $index => $filter_tab) {
			if ($filter_tab['filter_custom_time']) {
				$filter_tab['show'] = TRIGGERS_OPTION_ALL;
				$filter_tab['filter_src']['show'] = TRIGGERS_OPTION_ALL;
			}

			if ($index == $profile->selected) {
				// Initialize multiselect data for filter_scr to allow tabfilter correctly handle unsaved state.
				$filter_tab['filter_src']['filter_view_data'] = $this->getAdditionalData($filter_tab['filter_src']);
			}

			$filter_tabs[] = $filter_tab + ['filter_view_data' => $this->getAdditionalData($filter_tab)];
		}

		// filter
		$filter = $filter_tabs[$profile->selected];
		$refresh_curl = (new CUrl('zabbix.php'));
		$filter['action'] = 'availreport.view.refresh';
		// $filter['action_from_url'] = $this->getAction();
		array_map([$refresh_curl, 'setArgument'], array_keys($filter), $filter);
		// $timeselector_from = $filter['filter_custom_time'] == 0 ? $filter['from'] : $profile->from;
		// $timeselector_to = $filter['filter_custom_time'] == 0 ? $filter['to'] : $profile->to;
		if (!$this->hasInput('page')) {
			$refresh_curl->removeArgument('page');
		}

		$data = [
			'action' => $this->getAction(),
			'tabfilter_idx' => static::FILTER_IDX,
			'filter' => $filter,
			'filter_view' => 'monitoring.problem.filter',
			'filter_defaults' => $profile->filter_defaults,
			'tabfilter_options' => [
				'idx' => static::FILTER_IDX,
				'selected' => $profile->selected,
				'support_custom_time' => 1,
				'expanded' => $profile->expanded,
				'page' => $filter['page'],
				'timeselector' => [
					'from' => $profile->from,
					'to' => $profile->to,
					'disabled' => ($filter['show'] != TRIGGERS_OPTION_ALL || $filter['filter_custom_time'])
				] + getTimeselectorActions($profile->from, $profile->to)
			],
			'filter_tabs' => $filter_tabs,
			'refresh_url' => $refresh_curl->getUrl(),
			'refresh_interval' => CWebUser::getRefresh() * 1000,
			'inventories' => array_column(getHostInventories(), 'title', 'db_field'),
			'sort' => $filter['sort'],
			'sortorder' => $filter['sortorder'],
			'uncheck' => $this->hasInput('filter_reset'),
			'page' => $this->getInput('page', 1)
		];

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Availability report'));

		if ($data['action'] === 'availreport.view.csv') {
			$response->setFileName('zbx_availability_report_export.csv');
		}

		$this->setResponse($response);
	}
}
