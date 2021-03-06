<?php
/***********************************************************************
| PortSensor(tm) developed by WebGroup Media, LLC.
|-----------------------------------------------------------------------
| All source code & content (c) Copyright 2009, WebGroup Media LLC
|   unless specifically noted otherwise.
|
| By using this software, you acknowledge having read the license
| and agree to be bound thereby.
| ______________________________________________________________________
|	http://www.cerberusweb.com	  http://www.webgroupmedia.com/
***********************************************************************/

class PsTranslations extends DevblocksTranslationsExtension {
	function __construct($manifest) {
		parent::__construct($manifest);	
	}
	
	function getTmxFile() {
		return dirname(dirname(__FILE__)) . '/strings.xml';
	}
};

// Custom Field Sources

class PsCustomFieldSource_Sensor extends Extension_CustomFieldSource {
	const ID = 'portsensor.fields.source.sensor';
};

class PsCustomFieldSource_Worker extends Extension_CustomFieldSource {
	const ID = 'portsensor.fields.source.worker';
};

class PsWorklistSource_Sensor extends Extension_WorklistSource {
	const ID = 'core.worklist.source.sensor';
};

// Alert Actions

class PsAlertActionSendMail extends Extension_AlertAction {
	const EXTENSION_ID = 'portsensor.alert.action.send_mail';
	
	function __construct($manifest) {
		parent::__construct($manifest);	
	}

	function run(Model_Alert $alert, $sensors) {
    	@$to = DevblocksPlatform::parseCsvString($alert->actions[self::EXTENSION_ID]['to']);
    	@$template_subject = $alert->actions[self::EXTENSION_ID]['template_subject'];
    	@$template_body = $alert->actions[self::EXTENSION_ID]['template_body'];
    	
    	$logger = DevblocksPlatform::getConsoleLog();
    	
    	// Assign template variables
    	$tpl = DevblocksPlatform::getTemplateService();
    	$tpl->clear_all_assign();
		$tpl->assign('alert', $alert);
		$tpl->assign('sensors', $sensors);
		$tpl->assign('num_sensors', count($sensors));
		
		// Build template
		$tpl_builder = DevblocksPlatform::getTemplateBuilder();
		$errors = array();

		// Subject
		if(false == ($subject = $tpl_builder->build($template_subject)))
			$errors += $tpl_builder->getErrors();
		
		// Body
		if(false == ($body = $tpl_builder->build($template_body))) {
			$errors += $tpl_builder->getErrors();
		}

		if(!empty($errors)) {
			$logger->err(sprintf("Errors in mail template (skipping): %s",implode("<br>\r\n", $errors)));
			return false;
		}
		
		if(is_array($to))
		foreach($to as $address) {
			$logger->info(sprintf("Sending mail to %s about %d sensors", $address, count($sensors)));
			
			PortSensorMail::quickSend(
				$address,
				$subject,
				$body
			);
		}
	}
	
	function renderConfig(Model_Alert $alert=null) {
		$tpl = DevblocksPlatform::getTemplateService();
		$tpl_path = dirname(dirname(__FILE__)) . '/templates/';
		
		@$params = $alert->actions[self::EXTENSION_ID];
		$tpl->assign('params', $params);

		$tpl->assign('models', array(
			'alert' => get_class_vars("Model_Alert"),
			'sensors[sensor]' => get_class_vars("Model_Sensor"),
		));
		
		$tpl->display($tpl_path . 'alerts/actions/send_mail.tpl');
	}
	
	function saveConfig() { 
    	@$to = DevblocksPlatform::importGPC($_REQUEST['alert_action_mail_to'],'string',null);
    	@$subject = DevblocksPlatform::importGPC($_REQUEST['alert_action_mail_subject_tpl'],'string',null);
    	@$body = DevblocksPlatform::importGPC($_REQUEST['alert_action_mail_body_tpl'],'string',null);
		
        return array(
			'to' => $to,
        	'template_subject' => $subject,
        	'template_body' => $body,
		);
	}
};

class PsPageController extends DevblocksControllerExtension {
    const ID = 'core.controller.page';
    
	function __construct($manifest) {
		parent::__construct($manifest);
	}

	/**
	 * Enter description here...
	 *
	 * @param string $uri
	 * @return string $id
	 */
	public function _getPageIdByUri($uri) {
        $pages = DevblocksPlatform::getExtensions('portsensor.page', false);
        foreach($pages as $manifest) { /* @var $manifest DevblocksExtensionManifest */
            if(0 == strcasecmp($uri,$manifest->params['uri'])) {
                return $manifest->id;
            }
        }
        return NULL;
	}
	
	// [TODO] We probably need a PortSensorApplication scope for getting content that has ACL applied
	private function _getAllowedPages() {
		$active_worker = PortSensorApplication::getActiveWorker();
		$page_manifests = DevblocksPlatform::getExtensions('portsensor.page', false);

		// [TODO] This may cause problems on other pages where an active worker isn't required
		// Check RSS/etc (was bugged on login)
		
		// Check worker level ACL (if set by manifest)
		foreach($page_manifests as $idx => $page_manifest) {
			// If ACL policy defined
			if(isset($page_manifest->params['acl'])) {
				if($active_worker && !$active_worker->hasPriv($page_manifest->params['acl'])) {
					unset($page_manifests[$idx]);
				}
			}
		}
		
		return $page_manifests;
	}
	
	public function handleRequest(DevblocksHttpRequest $request) {
	    $path = $request->path;
		$controller = array_shift($path);

		// [TODO] _getAllowedPages() should take over, but it currently blocks hidden stubs
        $page_id = $this->_getPageIdByUri($controller);
		$page = DevblocksPlatform::getExtension($page_id, true); /* @var $page PortSensorPageExtension */
		
        if(empty($page)) {
	        switch($controller) {
//	        	case "portal":
//				    header("Status: 404");
//	        		die(); // 404
//	        		break;
	        		
	        	default:
	        		return; // default page
	        		break;
	        }
        }

	    @$action = DevblocksPlatform::strAlphaNumDash(array_shift($path)) . 'Action';

	    switch($action) {
	        case NULL:
	            // [TODO] Index/page render
	            break;
	            
	        default:
			    // Default action, call arg as a method suffixed with Action
			    
			    if($page->isVisible()) {
					if(method_exists($page,$action)) {
						call_user_func(array(&$page, $action)); // [TODO] Pass HttpRequest as arg?
					}
				} else {
					// if Ajax [TODO] percolate isAjax from platform to handleRequest
					// die("Access denied.  Session expired?");
				}

	            break;
	    }
	}
	
	public function writeResponse(DevblocksHttpResponse $response) {
	    $path = $response->path;
		// [JAS]: Ajax? // [TODO] Explore outputting whitespace here for Safari
//	    if(empty($path))
//			return;

		$tpl = DevblocksPlatform::getTemplateService();
		$session = DevblocksPlatform::getSessionService();
		$settings = DevblocksPlatform::getPluginSettingsService();
		$translate = DevblocksPlatform::getTranslationService();
	    $active_worker = PortSensorApplication::getActiveWorker();
		
		$visit = $session->getVisit();
		$page_manifests = $this->_getAllowedPages();

		$controller = array_shift($path);

		// Default page [TODO] This is supposed to come from framework.config.php
		if(empty($controller)) 
			$controller = 'home';

	    // [JAS]: Require us to always be logged in for PortSensor pages
		if(empty($visit) && 0 != strcasecmp($controller,'login')) {
			$query = array();
			if(!empty($response->path))
				$query = array('url'=> urlencode(implode('/',$response->path)));
			DevblocksPlatform::redirect(new DevblocksHttpRequest(array('login'),$query));
		}

	    $page_id = $this->_getPageIdByUri($controller);
		@$page = DevblocksPlatform::getExtension($page_id, true); /* @var $page PortSensorPageExtension */
        
        if(empty($page)) {
   		    header("Status: 404");
        	return; // [TODO] 404
		}
	    
		// [JAS]: Listeners (Step-by-step guided tour, etc.)
	    $listenerManifests = DevblocksPlatform::getExtensions('devblocks.listener.http');
	    foreach($listenerManifests as $listenerManifest) { /* @var $listenerManifest DevblocksExtensionManifest */
	         $inst = $listenerManifest->createInstance(); /* @var $inst DevblocksHttpRequestListenerExtension */
	         $inst->run($response, $tpl);
	    }

	    $tpl->assign('active_worker', $active_worker);
        $tour_enabled = false;
		
		if(!empty($visit) && !is_null($active_worker)) {
			$tour_enabled = intval(DAO_WorkerPref::get($active_worker->id, 'assist_mode', 1));

			$keyboard_shortcuts = intval(DAO_WorkerPref::get($active_worker->id,'keyboard_shortcuts',1));
			$tpl->assign('pref_keyboard_shortcuts', $keyboard_shortcuts);			
			
//	    	$active_worker_memberships = $active_worker->getMemberships();
//	    	$tpl->assign('active_worker_memberships', $active_worker_memberships);
			
			$unread_notifications = DAO_WorkerEvent::getUnreadCountByWorker($active_worker->id);
			$tpl->assign('active_worker_notify_count', $unread_notifications);
			
			DAO_Worker::logActivity($active_worker->id, $page->getActivity());
		}
		$tpl->assign('tour_enabled', $tour_enabled);
		
        // [JAS]: Variables provided to all page templates
		$tpl->assign('settings', $settings);
		$tpl->assign('session', $_SESSION);
		$tpl->assign('translate', $translate);
		$tpl->assign('visit', $visit);
		$tpl->assign('license',PortSensorLicense::getInstance());
		
		$tpl->assign('page_manifests',$page_manifests);		
		$tpl->assign('page',$page);

		$tpl->assign('response_uri', implode('/', $response->path));
		
		$core_tpl = APP_PATH . '/features/portsensor.core/templates/';
		$tpl->assign('core_tpl', $core_tpl);
		
		// Prebody Renderers
		$preBodyRenderers = DevblocksPlatform::getExtensions('portsensor.renderer.prebody', true);
		if(!empty($preBodyRenderers))
			$tpl->assign('prebody_renderers', $preBodyRenderers);

		// Postbody Renderers
		$postBodyRenderers = DevblocksPlatform::getExtensions('portsensor.renderer.postbody', true);
		if(!empty($postBodyRenderers))
			$tpl->assign('postbody_renderers', $postBodyRenderers);
		
		// Timings
		$tpl->assign('render_time', (microtime(true) - DevblocksPlatform::getStartTime()));
		if(function_exists('memory_get_usage') && function_exists('memory_get_peak_usage')) {
			$tpl->assign('render_memory', memory_get_usage() - DevblocksPlatform::getStartMemory());
			$tpl->assign('render_peak_memory', memory_get_peak_usage() - DevblocksPlatform::getStartPeakMemory());
		}
		
		$tpl->display($core_tpl.'border.tpl');
		
//		$cache = DevblocksPlatform::getCacheService();
//		$cache->printStatistics();
	}
};