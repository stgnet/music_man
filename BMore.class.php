<?php
// BMore - html/db support via schema
// NOTE: This is under GPL-3 License

//namespace FreePBX\libraries;

class BMoreContext {
	public $table_name;
	public $view_name;
	public $table;
	public $view;
}

class BMore extends \FreePBX_Helpers {
	private $unique_id_count;

	// preset defaults
	public function __construct()
	{
		$this->context = $this->getContext($_REQUEST['view'], $_REQUEST['table']);

		$this->display_query=array(
			'display' => $this->module_name,
			'table' => $this->context->table_name,
			'view' => $this->context->view_name,
		);
		$this->ajax_query=array(
			'module' => $this->module_name,
			'table' => $this->context->table_name,
			'view' => $this->context->view_name,
		);
	}

	// UTILITY FUNCTIONS

	// generate unique id
	function getId($prefix)
	{
		return $prefix.++$unique_id_count;
	}

	// generate smartly formatted html tags with params and optional content
	public function tag($tag, $params=array(), $content = false)
	{
		if (!is_array($params)) {
			if ($params) {
				$params = array('class' => $params);
			} else {
				$params = array();
			}
		}
		if (array_key_exists('class', $params) && is_array($params['class'])) {
			$params['class'] = implode(' ', $params['class']);
		}


		$html = '<' . $tag;

		$just_tag = strstr($tag, ' ', true);
		if ($just_tag) {
			$tag = $just_tag;
		}

		foreach ($params as $name => $value) {
			if ($value === false) {
				continue;
			}
			$html .= ' ' . $name;
			if ($value === true) {
				continue;
			}
			$html .= '="' . $value . '"';
		}
		$html .= '>';

		if ($content !== false) {
			if (strlen($content) > 40 && $tag != 'li') {
				$content = str_replace("\n", "\n  ", "\n" . $content) . "\n";
			}
			$html .= $content;
			$html .= '</' . $tag .'>';
		}
		return $html;
	}

	// make [icon]'s come alive
	public function iconize($label)
	{
		return preg_replace_callback('|\[[^]]*\]|',
			function ($icon) {
				return '<i class="fa fa-' . substr($icon[0], 1, -1) . '"></i>';
			},
			$label);
	}

	// set the Table and View pointers
	private function setTableView(&$table_name, &$view_name, &$table, &$view)
	{
		if (!$table_name) {
			$table_name = reset(array_keys($this->schema));
		}

		if (!array_key_exists($table_name, $this->schema)) {
			throw new \Exception('Invalid table: '.$table_name);
		}
		$table = $this->schema[$table_name];

		if (empty($table['views'])) {
			throw new \Exception('Schema for table '.$table_name.' has no views');
		}

		if (!$view_name) {
			$view_name = reset(array_keys($table['views']));
		}

		if (!array_key_exists($view_name, $table['views'])) {
			throw new \Exception('Invalid view: '.$view_name);
		}
		$view = $table['views'][$view_name];
	}

	// DATABASE HANDLING

	// map sql schema type to PDO param type
	public function getParamType($field)
	{
		if (strtoupper(substr($field['sqltype'], 0, 7)) == 'VARCHAR') {
			return \PDO::PARAM_STR;
		}
		if (strtoupper(substr($field['sqltype'], 0, 3)) == 'INT') {
			return \PDO::PARAM_INT;
		}
		if (strtoupper(substr($field['sqltype'], 0, 7)) == 'TINYINT') {
			return \PDO::PARAM_INT;
		}
		throw new \Exception('Unknown parameter type from sql type: '.$field['sqltype']);
	}

	// get a single record
	public function getRecord($table, $record){
		if (empty($table['table'])) throw new \Exception('Table not defined');
		$key_fields = array_filter($table['fields'], function($field) { return !empty($field['key']); });
		$wheres = implode(', ', array_map(function($value) { return "$value = :$value"; }, array_keys($key_fields)));
		$sql = "SELECT * FROM {$table['table']} WHERE $wheres";
		$stmt = $this->db->prepare($sql);
		foreach ($key_fields as $name => $field) {
			if (!array_key_exists($name, $record)) {
				throw new \Exception('getRecord '.$table['table'].' requires key '.$name);
			}
			$stmt->bindParam(':'.$name, $record[$name], $this->getParamType($field));
		}
		$stmt->execute();
		$row =$stmt->fetchObject();
		return (array)$row;
	}

	// get ALL! the records
	public function getAllRecords($table){
		if (empty($table['table'])) throw new \Exception('Table not defined ');
		$ret = array();
		$sql = "SELECT * from {$table['table']}";
		foreach ($this->db->query($sql, DB_FETCHMODE_ASSOC) as $row) {
			$ret[] = (array)$row;
		}
		return $ret;
	}

	// add a new record
	public function addRecord($table, $record){
		if (empty($table['table'])) throw new \Exception('Table not defined: '.print_r($table,true));
		if (empty($table['fields'])) throw new \Exception('Fields not defined');
		$non_key_fields = array_filter($table['fields'], function($field) { return empty($field['key']); });
		$field_names = implode(', ', array_keys($non_key_fields));
		$colon_names = implode(', ', array_map(function($value) { return ':'.$value; }, array_keys($non_key_fields)));

		$sql = "INSERT INTO {$table['table']} ($field_names) VALUES ($colon_names)";
		$stmt = $this->db->prepare($sql);
		foreach ($non_key_fields as $name => $field) {
			if (!array_key_exists($name, $record)) {
				$record[$name] = '';
			}
			$stmt->bindParam(':'.$name, $record[$name], $this->getParamType($field));
		}
		$stmt->execute();
		return $this->db->lastInsertId();
	}

	// update record (key must exist)
	public function updateRecord($table, $record){
		if (empty($table['table'])) throw new \Exception('Table not defined');
		if (empty($table['fields'])) throw new \Exception('Fields not defined');
		$non_key_fields = array_filter($table['fields'], function($field) { return empty($field['key']); });
		$key_fields = array_filter($table['fields'], function($field) { return !empty($field['key']); });
		//$assignments = array_reduce(array_keys($non_key_fields), function($carry, $name) { $carry .= "$name
		$assignments = implode(', ', array_map(function($value) { return "$value = :$value"; }, array_keys($non_key_fields)));
		$wheres = implode(', ', array_map(function($value) { return "$value = :$value"; }, array_keys($key_fields)));

		$sql = "UPDATE {$table['table']} SET $assignments WHERE $wheres";
		$stmt = $this->db->prepare($sql);
		foreach ($table['fields'] as $name => $field) {
			if (!array_key_exists($name, $record)) {
				$record[$name] = '';
			}
			$stmt->bindParam(':' . $name, $record[$name], $this->getParamType($field));
		}
		return $stmt->execute();
	}

	// delete record
	public function deleteRecord($table, $record){
		if (empty($table['table'])) throw new \Exception('Table not defined');
		if (empty($table['fields'])) throw new \Exception('Fields not defined');
		$non_key_fields = array_filter($table['fields'], function($field) { return empty($field['key']); });
		$key_fields = array_filter($table['fields'], function($field) { return !empty($field['key']); });
		$wheres = implode(', ', array_map(function($value) { return "$value = :$value"; }, array_keys($key_fields)));

		$sql = "DELETE FROM {$table['table']} WHERE $wheres";
		$stmt = $this->db->prepare($sql);
		foreach ($key_fields as $name => $field) {
			if (!array_key_exists($name, $record)) {
				throw new \Exception('delete does not specify field ' . $name);
			}
			$stmt->bindParam(':' . $name, $record[$name], $this->getParamType($field));
		}
		return $stmt->execute();
	}

	// DATABASE UTILITY

	// returns merged set of view and schema fields to populate view
	public function getMergedFields($view_fields, $context = Null)
	{
		if (empty($context)) {
			$table = $this->context->table;
		} else {
			$table = $context->table;
		}

		if (empty($view_fields)) {
			return $table['fields'];
		}

		$fields = array();
		foreach ($view_fields as $name => $field)
		{
			if (!is_array($field)) {
				$name = $field;
				if ($name == '*') {
					$fields = array_merge(fields, $table['fields']);
					continue;
				}
				if (!array_key_exists($name, $table['fields'])) {
					throw new \Exception('Invalid field name: ' + $name);
				}
				$fields[$name] = $table['fields'][$name];
			} else {
				if (array_key_exists($name, $table['fields'])) {
					$fields[$name] = array_merge($table['fields'][$name], $field);
				} else {
					$fields[$name] = $field;
				}
			}
		}
		return $fields;
	}

	// VIEW GENERATION

	// returns html 'get' url
	public function getDisplayUrl($params=array())
	{
		return 'config.php?' . http_build_query(array_merge($this->display_query, $params));
	}

	// returns ajax url for table getter
	public function getAjaxUrl($params=array())
	{
		return 'ajax.php?' . http_build_query(array_merge($this->ajax_query, $params));
	}

	// convert merged view schema to html using 'viewHandler' functions
	public function getViewAsHtml($context=Null, $contents=array())
	{
		$html = array();
		$default = array();

		if (!$context) {
			$context = $this->context;
		}

		if (empty($context->view)) return '';

		foreach ($context->view as $handler => $data) {
			if ($handler == 'rnav' || $handler == 'default') {
				continue;
			}
			$func = 'view'.ucwords($handler);
			if (method_exists($this, $func)) {
				$html[] = $this->$func($data, $context, $contents);
			} else if (function_exists($func)) {
				$html[] = $func(array_merge_recursive($default, $data), $context, $contents);
			} else {
				throw new \Exception('Unable to locate view handler for '.$handler);
			}
		}
		return implode("\n", $html);
	}

	// merge view schema in priority order
	public function getContext($view_name=Null, $table_name = Null)
	{
		if (empty($this->context)) {
			if (empty($table_name)) {
				$table_name = reset(array_keys($this->schema));
			}
			if (empty($view_name)) {
				$view_name = reset(array_keys($this->schema[$table_name]['views']));
			}
		} else {
			if (!$table_name) {
				$table_name = $this->context->table_name;
			}
			if (!$view_name) {
				$view_name = $this->context->view_name;
			}
		}
		$context = new BMoreContext();

		$context->table_name = $table_name;
		$context->table = $this->schema[$table_name];

		$context->view_name = $view_name;

		$view = array();
		if (!empty($context->table['views'][$view_name])) {
			$view = $context->table['views'][$view_name];
		}

		$table_default = array();
		if (!empty($context->table['views']['default'])) {
			$table_default = $context->table['views']['default'];
		}

		$default_view = array();
		if (!empty($this->schema['default']['views'][$view_name])) {
			$default_view = $this->schema['default']['views'][$view_name];
		}

		$default_default = array();
		if (!empty($this->schema['default']['views']['default'])) {
			$default_default = $this->schema['default']['views']['default'];
		}
		$context->view = array_merge_recursive($default_default, $default_view, $table_default, $view);

		return $context;
	}

	// SCHEMA-DRIVEN VIEW HANDLERS

	// generate html from view data structured as nested html tags with optional contents
	public function viewHtml($data, $context, $contents)
	{
		if (!is_array($data)) {
			if ($data[0] == '$') {
				$name = substr($data, 1);
				if (array_key_exists($name, $contents)) {
					return $contents[$name];
				}
//else return print_r(array($data, $name, $contents), true);
			}
			return $data;
		}
		$html = array();
		foreach ($data as $tag => $contains)
		{
			$html[] = $this->tag($tag, array(), $this->viewHtml($contains,$context, $contents));
		}
		return implode("\n", $html);
	}


	// generate html for data table in view (grid)
	public function viewTable($data, $context, $contents)
	{
		$scripts = array();
		$url = $this->getAjaxUrl(array('command'=>'getJSON'));
		$params = empty($data['params']) ? array() : $data['params'];
		$params['id'] = $this->getId('table');
		$params['data-url'] = $this->getAjaxUrl(array('command' => 'getJSON'));

		$html = array();
		if (!empty($data['toolbar'])) {
			$id = $this->getId('toolbar');
			$html[] = $this->tag('div', array('id' => $id), $this->htmlLinks($data['toolbar']));
			$params['data-toolbar'] = '#' . $id;
		}

		$tr = array();
		foreach ($this->getMergedFields($data['fields']) as $name => $field) {
			if (!$field['header']) continue;
			$th_params = array(
				'class' => 'col-md-1',
				'data-field' => $name,
			);
			if (!empty($field['data-formatter'])) {
				$script_name = $context->table_name.'_'.$name.'_formatter';
				$scripts[$script_name] = str_replace('$module_name', $this->module_name, $field['data-formatter']);
				$th_params['data-formatter'] = $script_name;
			}
			$tr[] = $this->tag('th', $th_params, $field['header']);
		}
		$html[] = $this->tag('table', $params,
				$this->tag('thead', array(),
					$this->tag('tr', array(), implode("\n", $tr))
				)
			);

		if (!empty($scripts)) {
			$js = '';
			foreach ($scripts as $name => $script) {
				$js .= 'function '.$name.'(value, row, index){'.str_replace("\n", "\n  ", "\n".$script)."\n} ";
			}
			$html[] = $this->tag('script', array('type' => 'text/javascript'), $js);
		}
		return implode("\n", $html);
	}

	// generate html for form in view
	public function viewForm($data, $context, $contents)
	{
		$hidden_action_value = 'add';
		$form_contents = array();
		$form_params = array(
			'class' => 'fpbx-submit',
			'action' => '',
			'method' => 'post',
			'id' => $this->getId('form'),
			// needs a name ?
		);
		$id = false;
		if (!empty($_REQUEST['id'])) {
			$id = $_REQUEST['id'];
		}
		$values = array();
		if ($id) {
			$form_params['data-fpbx-delete'] = $this->getDisplayUrl(array('action'=>'delete', 'id'=>$id));
			$hidden_action_value = 'edit';
			$values = $this->getRecord($context->table, array('id' => $id));
			if (!$values) $values = array();
		}
		$form_contents[] = $this->tag('input', array(
			'type' => 'hidden',
			'name' => 'action',
			'value' => $hidden_action_value
		));

		foreach ($this->getMergedFields($data['fields']) as $name => $field) {
			if (empty($field['header'])) continue;
			if (empty($field['type'])) {
				$field['type'] = 'text';
			}
			$value = '';
			if (array_key_exists($name, $values)) {
				$value = $values[$name];
			}
			$form_field_class='';
			if (!empty($field['select'])) {
				$options = array();
				foreach ($field['select'] as $value => $text) {
					$options[] = $this->tag('option', array('value' => $value), $text);
				}
				$form_field = $this->tag('select', array(
					'class' => 'form-control',
					'id' => $name,
					'name' => $name,
				), implode("\n", $options));
			} else if (strtoupper($field['sqltype']) == 'TINYINT(1)') {
				$form_field_class=' radioset';
				$form_field =
					$this->tag('input', array(
						'type' => 'radio',
						'id' => $name.'-yes',
						'name' => $name,
						'value' => '1',
						'CHECKED' => ($value != 0))
					)."\n".
					$this->tag('label', array('for' => $name.'-yes'), 'Yes')."\n".
					$this->tag('input', array(
						'type' => 'radio',
						'id' => $name.'-no',
						'name' => $name,
						'value' => '0',
						'CHECKED' => ($value == 0))
					)."\n".
					$this->tag('label', array('for' => $name.'-no'), 'No');
			} else {
				$form_field = $this->tag('input', array(
					'type' => $field['type'],
					'class' => 'form-control',
					'id' => $name,
					'name' => $name,
					'value' => $value,
				));
			}
			$form_contents[] = $this->tag('div', 'element-container',
				$this->tag('div', 'row',
					$this->tag('div', 'col-md-12',
						$this->tag('div', 'row',
							$this->tag('div', 'form-group',
								$this->tag('div', 'col-md-3',
									$this->tag('label', array(
										'class' => 'control-label',
										'for' => $name
									), $field['header']).
									$this->tag('i', array(
										'class' => 'fa fa-question-circle fpbx-help-icon',
										'data-for' => $name
									), "")
								).
								$this->tag('div', 'col-md-9'.$form_field_class,
									$form_field
								)
							)
						)
					)
				)."\n".
				$this->tag('div', 'row',
					$this->tag('div', 'col-md-12',
						$this->tag('span', array(
							'id' => $name.'-help',
							'class' => 'help-block fpbx-help-block'
						), $field['help'])
					)
				)
			);
		}
		return $this->tag('form', $form_params, implode("\n", $form_contents));
	}

	// OTHER NOT SCHEMA-DRIVEN VIEW GENERATORS

	// wrap view in a modal dialog
	private function getModalDialogView($header, $id, $view_name)
	{
		$modal_params = array(
			'class' => 'modal fade',
			'id' => $id,
			'tabindex' => '-1',
			'role' => 'dialog',
			'aria-hidden' => 'true'
		);
		$close_params = array(
			'type' => 'button',
			'class' => 'close',
			'data-dismiss' => 'modal',
			'aria-label' => 'Close'
		);

		return $this->tag('div', $modal_params,
			$this->tag('div', 'modal-dialog',
				$this->tag('div', 'modal-content',
					$this->tag('div', 'modal-header',
						$this->tag('button', $close_params,
							$this->tag('span', array('aria-hidden' => 'true'),
								'&times;')
						)."\n".
						$this->tag('h4', array('class' => 'modal-title', 'id' => $id.'Label'),
							$this->iconize($header)
						)
					)."\n".
					$this->tag('div', 'modal-body',
						$this->getViewAsHtml($this->getContext($view_name))
					)
				)
			)
		);
	}

	// recursively follow array tree building html output
	private function htmlLinksRecursor($links, $subitem, &$tail)
	{
		$sword = array();
		foreach ($links as $text => $link) {
			$class = '';
			if (strstr($text, '|')) {
				list($class, $text) = explode('|', $text, 2);
			}
			if ($link[0] == '@') {
				// modal link to another view
				$modal_view = substr($link, 1);
				$modal_id = $this->getId('modal');
				$sword[] = $this->tag('button', array('class' => $class, 'data-toggle' => 'modal',
					'data-target' => '#'.$modal_id),
					$this->iconize($text));
				$tail[] = $this->getModalDialogView($text, $modal_id, $modal_view);
			} else if (is_array($link)) {
				$sword[] = $this->tag('div', 'btn-group',
					$this->tag('button',
						array('class' => "$class dropdown-toggle", 'type' => 'button',
							'data-toggle' => 'dropdown', 'aria-expanded' => 'false'),
						$this->iconize($text) . ' ' . $this->tag('span class="caret"')
					)."\n".
					$this->tag('ul', array('class' => 'dropdown-menu', 'role' => 'menu'),
						$this->htmlLinksRecursor($link, true, $tail))
				);
			} else if ($subitem) {
				$sword[] = $this->tag('li', array(),
					$this->tag('a', array('class' => $class, 'href' => $link), $this->iconize($text))
				);
			} else {
				$sword[] = $this->tag('a', array('class' => $class, 'href' => $link), $this->iconize($text)) ;
			}
		}
		return implode("\n", $sword);
	}

	// generate html for set of links
	public function htmlLinks($links)
	{
		$tail = array();
		$html = $this->htmlLinksRecursor($links, false, $tail);
		return $html . implode("\n", $tail);
	}

	// View called by page.{$module_name}.php
	public function showPage()
	{
		echo "\n";
		echo $this->getViewAsHtml($this->getContext('page'), array('content' =>
			$this->getViewAsHtml()
		));
		echo "\n";
	}

	// DEFAULT BMO HANDLERS (override as needed)

	// right side pop-out navigation bar
	public function getRightNav($request) {
		if (empty($this->context->view['rnav'])) {
			return;
		}

		// fake a new context using the subview (this should be fixed to properly merge)
		$context = $this->getContext();
		$context->view = $context->view['rnav'];

		// use this view's rnav sub-view
		return "\n".$this->getViewAsHtml($context)."\n";
	}

	// floating action bar
	public function getActionBar($request) {
		$buttons = array();
		switch($request['display']) {
			//this is usually your module's rawname
			case $this->module_name:
				$buttons = array(
					'delete' => array(
						'name' => 'delete',
						'id' => 'delete',
						'value' => _('Delete')
					),
					'reset' => array(
						'name' => 'reset',
						'id' => 'reset',
						'value' => _('Reset')
					),
					'submit' => array(
						'name' => 'submit',
						'id' => 'submit',
						'value' => _('Submit')
					)
				);
				//We hide the delete button if we are not editing an item. "id" should be whatever your unique element is.
				if (empty($request['id'])) {
					unset($buttons['delete']);
				}
				//If we are not in the form view lets 86 the buttons
// TODO: this isn't working right
				if (empty($request['view'])){
					unset($buttons);
				}
			break;
		}
		return $buttons;
	}

	// handle cruddy requests
	public function doConfigPageInit($page) {
		if (!empty($_REQUEST['action'])) {
			switch ($_REQUEST['action']) {
				case 'add':
					$id = $this->addRecord($this->context->table, $_REQUEST);
					$_REQUEST['id'] = $id;
				break;
				case 'edit':
					$this->updateRecord($this->context->table, $_REQUEST);
				break;
				case 'delete':
					$this->deleteRecord($this->context->table, $_REQUEST);
				break;
				case 'reinstall': // dev util for db schema change (also erases all data)
					echo '<pre>';
					$this->uninstall();
					$this->install();
					echo '</pre>';
				break;
				default:
					throw new \Exception('Invalid action: '.$_REQUEST['action']);
			}
			unset($_REQUEST['action']);
		}
	}

	// standard install/uninstall
	public function install()
	{
		foreach ($this->schema as $dbname => $table) {
			if ($dbname == 'default') continue;
			out(_('Creating database table ' . $dbname));
			$bogus='haha';
			$definitions = implode(', ', array_map(
				function($name) use ($table) {
					return "`$name` {$table['fields'][$name]['sqltype']}";
				}, array_keys($table['fields'])));
			$sql = "CREATE TABLE IF NOT EXISTS {$table['table']} ($definitions);";
			$this->db->query($sql);
		}
	}
	public function uninstall()
	{
		foreach ($this->schema as $dbname => $table) {
			if ($dbname == 'default') continue;
			out(_('Removing database table ' . $dbname));
			$sql = "DROP TABLE IF EXISTS {$table['table']};";
			$this->db->query($sql);
		}
	}

	// backup/restore not implemented
	public function backup() {}
	public function restore($backup) {}

	// check for valid ajax request
	public function ajaxRequest($req, &$setting) {
		//The ajax request
		if ($req == "getJSON") {
			//Tell BMO This command is valid. If you are doing a lot of actions use a switch
			return true;
		}else{
			//Deny everything else
			return false;
		}
	}
	// process ajax request
	public function ajaxHandler() {
		if($_REQUEST['command'] == 'getJSON'){
			return $this->getAllRecords($this->context->table);
		}
	}
}
