<?php
//All module classes should be namespaced
namespace FreePBX\modules;

$library = dirname(dirname(__DIR__)).'/libraries/BMore.class.php'; 
if (!file_exists($library)) {
	// this is temporary until BMore is in libraries path
	$library = 'BMore.class.php';
}
include_once $library;

//This setting is for AJAX calls. We want calls to be authenticated and so don't want cross origin calls
$setting = array('authenticate' => true, 'allowremote' => false);

class Music_man extends \BMore implements \BMO {
	public function __construct($freepbx = null) {
		global $cur_menuitem;
		$this->module_name = $cur_menuitem['module']['rawname'];

		if ($freepbx == null) {
			throw new \Exception("Not given a FreePBX Object");
		}
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;

		// define schema for databases and views
		$this->schema = array(
			'music' => array(
				'table' => 'music',
				'fields' => array(
					'id' => array(
						'key' => True,
						'header' => False,
						'sqltype' => 'INT(11) AUTO_INCREMENT PRIMARY KEY'
					),
					'category' => array(
						'header' => _("Category"),
						'help' => _("Enter a category for the music"),
						'sqltype' => 'VARCHAR(190)'
					),
					'type' => array(
						'header' => _("Type"),
						'help' => _("Select category type"),
						'select' => array(
							'files' => 'Files',
							'custom' => 'Custom Application',
						),
						'sqltype' => 'VARCHAR(100)'
					),
					'random' => array(
						'header' => _("Random"),
						'help' => _("Music files play in random order"),
						'sqltype' => 'tinyint(1)',
					),
					'application' => array(
						'header' => _("Application"),
						'help' => _("Enter executation path for streaming application"),
						'sqltype' => 'VARCHAR(255)',
					),
					'format' => array(
						'header' => _("Format"),
						'help' => _("Select format of files"),
						'sqltype' => 'VARCHAR(10)'
					),
				),
				'views' => array(
					'list' => array(
						'table' => array(
							'toolbar' => array(
								'btn|[plus] Add Category' => '@add',
								'btn|[gear] Menu' => array(
									'btn|[bug] JSON Test' => 'ajax.php?module='.$this->module_name.'&table=music&view=list&command=getJSON',
									'btn btn-danger|[exclamation-triangle] Erase all data' => '?display='.$this->module_name.'&action=reinstall',
								)
							),
							'fields' => array(
								'category',
								'type',
								'random',
								'action' => array(
									'header' => _("Action"),
									'data-formatter' => <<<'JS'
return '<a href="?display=$module_name&view=edit&id='+row.id+'"><i class="fa fa-edit"></i></a>&nbsp;<a class="delAction" href="?display=$module_name&action=delete7id='+row.id+'"><i class="fa fa-trash"></i></a>';
JS
								),
							),
						),
					),
					'add' => array(
						'form' => array(
							'fields' => array(
								'category',
								'type'
							)
						)
					),
					'edit' => array(
						'form' => array(),
						'rnav' => array(
							'table' => array(
								'toolbar' => array(
									'btn|[list] List' => '?display='.$this->module_name.'&table=music&view=list',
								),
								'fields' => array(
									'name' => array(
										'header' => _("Music"),
										'data-formatter' => <<<'JS'
return '<a href="?display=$module_name&table=music&view=edit&id='+row.id+'">'+row.name+'</a>';
JS
									)
								)
							)
						)
					)
				),
			),
			'default' => array(
				'views' => array(
					'page' => array(
						'html' => array(
							'div class="container-fluid"' => array(
								'h1' => _("Music Man"),
								'div class="display full-border"' => array(
									'div class="row"' => array(
										'div class="col-sm-12"' => array(
											'div class="fpbx-container"' => array(
												'div class="display full-border"' => '$content',
											)
										)
									)
								)
							)
						)
					),
					'list' => array(
						'table' => array(
							'params' => array(
								'class' => array('table', 'table-striped'),
								'data-cache' => 'false',
								'data-maintain-selected' => 'true',
								'data-show-refresh' => 'true',
								'data-show-columns' => 'true',
								'data-show-toggle' => 'true',
								'data-toggle' => 'table',
								'data-pagination' => 'true',
								'data-seaarch' => 'true',
							)
						)
					),
					'rnav' => array(
						'table' => array(
							'params' => array(
								'class' => array('table'),
								'data-cache' => 'false',
								'data-toggle' => 'table',
								'data-seaarch' => 'true',
							)
						)
					)
				)
			),
		);

		return parent::__construct();
	}

	public function uninstall()
	{
		// override default and don't delete the music table
	}
}
