<?php

$form = (new CForm())->setName('availreport_view');

$table = (new CTableInfo());

$view_url = $data['view_curl']->getUrl();



// $options = [
// 	'mode' => SCREEN_MODE_JS,
// 	'page' => $data['paging'],
// 	'data' => [
// 		'action' => $data['action'],
// 		'sort' => $data['sort'],
// 		'sortorder' => $data['sortorder'],
// 		'filter' => [
// 			'groupids' => $data['filter']['groupids'],
// 			'hostids' => $data['filter']['hostids'],
// 			'triggerids' => $data['filter']['triggerids']
// 		],
// 		'limit' => CSettingsHelper::get(CSettingsHelper::SEARCH_LIMIT)
// 	]
// ];

// echo CScreenBuilder::getScreen($options)->get();

// print_r($data['filter']['sort']);

// if (array_key_exists('view_curl', $data)) {
// 	$view_url = $data['view_curl'];
// 	$arr = explode('&', $view_url, -1);
// 	print_r($arr);
// }
// $filter = $data['filter'];

$table->setHeader([
	(new CColHeader(_('Host'))),
	(new CColHeader(_('Trigger'))),
	(new CColHeader(_('Problems'))),
	(new CColHeader(_('Ok'))),
	// (new CColHeader(_('Tags'))),
	make_sorting_header(_('Number of triggers'), 'cnt_event', $data['filter']['sort'], $data['filter']['sortorder'], $view_url)
]);

$allowed_ui_problems = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);

$triggers = $data['triggers'];

// $tags = makeTags($triggers, true, 'triggerid', ZBX_TAG_COUNT_DEFAULT);
// foreach ($triggers as &$trigger) {
// 	$trigger['tags'] = $tags[$trigger['triggerid']];
// }
// unset($trigger);

foreach ($triggers as $trigger) {

	$hostId = $trigger['hosts'][0]['hostid'];
	
	$hostName = (new CLinkAction($trigger['hosts'][0]['name']))->setMenuPopup(CMenuPopupHelper::getHost($hostId));
	// if ($data['hosts'][0]['status'] == HOST_STATUS_NOT_MONITORED) {
	// 	$hostName->addClass(ZBX_STYLE_RED);
	// }

	$triggerDescription = (new CLinkAction($trigger['description']))
		->setMenuPopup(CMenuPopupHelper::getTrigger([
			'triggerid' => $trigger['triggerid'],
			'backurl' => (new CUrl('toptriggers.php'))->getUrl()
	]));


	$table->addRow([
		$hostName,
		// $allowed_ui_problems
		// 	? new CLink($trigger['description'],
		// 		(new CUrl('zabbix.php'))
		// 			->setArgument('action', 'problem.view')
		// 			->setArgument('filter_name', '')
		// 			->setArgument('triggerids', [$trigger['triggerid']])
		// 	)
		// 	: $trigger['description'],
		$allowed_ui_problems ? $triggerDescription : $trigger['description'],
		// $triggerDescription,
		($trigger['availability']['true'] < 0.00005)
			? ''
			: (new CSpan(sprintf('%.4f%%', $trigger['availability']['true'])))->addClass(ZBX_STYLE_RED),
		($trigger['availability']['false'] < 0.00005)
			? ''
			: (new CSpan(sprintf('%.4f%%', $trigger['availability']['false'])))->addClass(ZBX_STYLE_GREEN),
		// $trigger['tags'],
		$trigger['cnt_event']
	]);
}

// $data = [
// 	'view_curl' => $view_url,
// 	'filter' => $filter,
// 	'paging' => $data['paging']
// ];

// print_r($data)
$form->addItem([$table,	$data['paging']]);

echo $form;
?>
