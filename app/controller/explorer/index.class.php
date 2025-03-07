<?php
/*
* @link http://kodcloud.com/
* @author warlee | e-mail:kodcloud@qq.com
* @copyright warlee 2014.(Shanghai)Co.,Ltd
* @license http://kodcloud.com/tools/license/license.txt
*/

class explorerIndex extends Controller{
	private $model;
	public function __construct(){
		parent::__construct();
		$this->model = Model("Source");
	}
	public function pathInfo(){
		$fileList = json_decode($this->in['dataArr'],true);
		if(!$fileList){
			show_json(LNG('explorer.error'),false);
		}
		$result = array();
		for ($i=0; $i < count($fileList); $i++){// 处理掉无权限和不存在的内容;
			if(!Action('explorer.auth')->can($fileList[$i]['path'],'show')) continue;
			
			$itemInfo = $this->itemInfo($fileList[$i]);
			if($itemInfo){$result[] = $itemInfo;}
		}
		if(count($fileList) == 1 && $result){
			$result = $this->itemInfoMore($result[0]);
		}
		$data = !!$result ? $result : LNG('common.pathNotExists');
		show_json($data,!!$result);
	}
	private function itemInfo($item){
		$path = $item['path'];
		$type = _get($item,'type');
		if($this->in['getChildren'] == '1'){
			$result = IO::infoWithChildren($path);
		}else if($type == 'simple'){
			$result = IO::info($path);
		}else{
			$result = IO::infoFull($path);
		}
		if(!$result) return false;
		// $canLink = Action('explorer.auth')->fileCanDownload($path);
		$canLink = Action('explorer.auth')->fileCan($path,'edit');//edit,share; 有编辑权限才能生成外链;
		if( $result['type'] == 'file' && $canLink){
			$result['downloadPath'] = Action('explorer.share')->link($path);
		}
		$result = Action('explorer.list')->pathInfoParse($result,0,1);
		if($result['isDelete'] == '1'){unset($result['downloadPath']);}
		return $result;
	}

	private function itemInfoMore($item){
		$result  = Model('SourceAuth')->authOwnerApply($item);
		$showMd5 = Model('SystemOption')->get('showFileMd5') != '0';
		if($result['type'] != 'file') return $item;
		if(!$showMd5) return $item;
		
		if( !_get($result,'fileInfo.hashMd5') && 
			($result['size'] <= 100*1024*1024 || _get($this->in,'getMore') || _get($this->in,'getChildren'))  ){
			$result['hashMd5'] = IO::hashMd5($result['path']);
		}
		$result = Action('explorer.list')->pathInfoMore($result);
		$result = Action('explorer.list')->fileInfoAddHistory($result);
		return $result;
	}
	
	public function desktopApp(){
		$desktopApps = include(BASIC_PATH.'data/system/desktop_app.php');
		$desktopApps['myComputer']['value'] = MY_HOME;// {source:home} 不指定打开文件夹,打开最后所在文件夹;

		if( !Action('explorer.listBlock')->pathEnable('my') ){
			unset($desktopApps['myComputer']);
		}
		if($this->config['settings']['disableDesktopHelp'] == 1){
			unset($desktopApps['userHelp']);
		}
		foreach ($desktopApps as $key => &$item) {
			if($item['menuType'] == 'menu-default-open'){
				$item['menuType'] = 'menu-default';
			}
			if(!_get($GLOBALS,'isRoot') && $item['rootNeed']){
				unset($desktopApps[$key]);
			}
		};unset($item);
		show_json($desktopApps);
	}

	/**
	 * 设置文档描述;
	 */
	public function setDesc(){
		$maxLength = $GLOBALS['config']['systemOption']['fileDescLengthMax'];
		$msg = LNG('explorer.descTooLong').'('.LNG('explorer.noMoreThan').$maxLength.')';
		$data = Input::getArray(array(
			'path'	=> array('check'=>'require'),
			'desc'	=> array('check'=>'length','param'=>array(0,$maxLength),'msg'=>$msg),
		));
		
		$result = false;
		$info   = IO::infoSimple($data['path']);
		if($info && $info['sourceID']){
			$result = $this->model->setDesc($info['sourceID'],$data['desc']);
		}
		// $msg = !!$result ? LNG('explorer.success') : LNG('explorer.error');
		show_json($data['desc'],!!$result);
	}
	
	/**
	 * 设置文档描述;
	 */
	public function setMeta(){
		$this->thumbClear();
		$data = Input::getArray(array(
			'path'	=> array('check'=>'require'),
			'data'	=> array('check'=>'require'),
		));
		$meta = json_decode($data['data'],true);
		if(!$meta || !is_array($meta)){
			show_json(LNG('explorer.error'),false);
		}

		$info = IO::info($data['path']);
		$this->sourceSecretApply($meta,$info);
		if($info && $info['sourceID']){
			foreach ($meta as $key => $value) {
				if( !$this->metaKeyCheck($key,$value,$info) ){
					show_json("key error!",false);
				}
				$value = $value === '' ? null:$value; //为空则删除;
				$this->model->metaSet($info['sourceID'],$key,$value);
			}
			show_json(IO::info($data['path']),true);
		}
		show_json(LNG('explorer.error'),false);
	}
	private function metaKeyCheck($key,$value,$info){
		static $metaKeys = false;
		if(!$metaKeys){
			$metaKeys = array_keys($this->config['settings']['sourceMeta']);
			$metaKeys = array_merge($metaKeys,array(
				'systemSort',		// 置顶
				'systemLock',		// 编辑锁定
				'systemLockTime',	// 编辑锁定时间
			));
		}
		$isLock = _get($info,'metaInfo.systemLock') ? true:false;
		if($key == "systemLock" && $value && $isLock){
			show_json(LNG('explorer.fileLockError'),false);
		}
		return in_array($key,$metaKeys);
	}
	
	// 文档密级处理;
	private function sourceSecretApply(&$meta,$pathInfo){
		$key = 'user_sourceSecret';
		if(!isset($meta[$key])) return;
		$sourceSecret = $meta[$key].'';
		unset($meta[$key]);
		// Model('SourceSecret')->clear(); //清除所有 debug;
		// Model("SystemOption")->set(array('sourceSecretList'=>'','sourceSecretMaxID'=>''));
		
		// 检测支持: 是否开启密级;自己是否为系统管理员或密级管理者; 是否为部门文档;
		$systemOption = Model("SystemOption")->get();
		if($pathInfo['targetType'] != 'group') return;
		if($systemOption['sourceSecret'] != '1') return;
		$allowUser  = explode(',',$systemOption['sourceSecretSetUser']);
		if(!$GLOBALS['isRoot'] && !in_array(USER_ID,$allowUser)) return;
		
		$sourceID = $pathInfo['sourceID'];
		$model = Model('SourceSecret');
		$find  = $model->findByKey('sourceID',$sourceID);
		$data  = array('sourceID'=>$sourceID,'typeID'=>$sourceSecret,'createUser'=>USER_ID);
		if($sourceSecret){
			if($find){$model->update($find['id'],$data);}
			if(!$find){$model->insert($data);}
		}else{
			if($find){$model->remove($find['id']);}
		}
	}
	

	// 清除文件缩略图
	private function thumbClear(){
		$data = Input::getArray(array(
			'path'	=> array('check'=>'require'),
			'clear'	=> array('default'=>0),
		));
		if ($data['clear'] != '1') return;
		// 临时目录不存在
		if (!IO::exist(IO_PATH_SYSTEM_TEMP)) show_json(LNG('explorer.success'));

		$fileInfo = IO::info($data['path']);
		// 后端缩略图
		if ($sourceID = IO::fileNameExist(IO_PATH_SYSTEM_TEMP, 'thumb')) {
			$imageMd5 = _get($fileInfo,'fileInfo.hashMd5',_get($fileInfo,'fileInfo.hashSimple'));
			if (!$imageMd5) {
				$imageMd5 = md5("{$fileInfo['name']}_{$fileInfo['path']}_{$fileInfo['size']}");
			}
			$thumbPath = KodIO::make($sourceID);
			$thumbList = array(250,600,1200,2000,3000,5000);	// 缩略图尺寸
			// 循环删除各尺寸缩略图
			foreach ($thumbList as $width) {
				$imageName = "{$imageMd5}_{$width}.png";
				if($sourceID = IO::fileNameExist($thumbPath, $imageName)){
					$imageTemp = KodIO::make($sourceID);
					IO::remove($imageTemp, false);
				}
			}
		}
		// 插件缩略图
		$plugin = IO_PATH_SYSTEM_TEMP . 'plugin/fileThumb';
		$pathInfo = IO::infoFull($plugin);
		if (isset($pathInfo['path'])) {
			// 缩略图临时文件目录
			$folderName = _get($fileInfo,'fileInfo.hashSimple');
			if (!$folderName) {
				$columns = array($fileInfo['name'], $fileInfo['path'],$fileInfo['size']);
				if(isset($fileInfo['parentLevel'])) $columns[] = $fileInfo['parentLevel'];
				$folderName = md5(implode('_', $columns));
			}
			// 直接删除目录
			if($sourceID = IO::fileNameExist($pathInfo['path'], $folderName)){
				$thumbPath = KodIO::make($sourceID);
				IO::remove($thumbPath, false);
			}
		}
		// 删除元数据
		$cacheKey = 'fileInfo.'.md5($fileInfo['path'].'@'.$fileInfo['size'].$fileInfo['modifyTime']);
		$fileID   = _get($fileInfo,'fileInfo.fileID',_get($fileInfo,'fileID'));
		Cache::remove($cacheKey);
		if($fileInfo['sourceID']){Model('Source')->metaSet($fileInfo['sourceID'],'modifyTimeShow',time());}
		if($fileID){Model("File")->metaSet($fileID,'fileInfoMore',null);};
		show_json(LNG('explorer.success'));
	}

	/**
	 * 设置权限
	 */
	public function setAuth(){
		$result = false;
		$actionAllow = array(
			'getData','clearChildren','getAllChildren','getGroupUser',
			'getAllChildrenByUser','setAllChildrenByUser','chmod',
		);
		$data = Input::getArray(array(
			'path'	=> array('check'=>'require'),
			'auth'	=> array('check'=>'json','default'=>''),
			'action'=> array('check'=>'in','default'=>'','param'=>$actionAllow),
		));
		
		// local,chmod;
		if($data['action'] == 'chmod'){
			$mode = intval($this->in['auth'],8);
			if($mode){$result = chmod_path($data['path'],$mode);}
			$msg = !!$result ? LNG('explorer.success') : LNG('explorer.error');
			show_json($msg,!!$result);
		}
		
		$info   = IO::info($data['path']);
		if( $info && $info['sourceID'] && $info['targetType'] == 'group'){//只能设置部门文档;
			$groupID = $info['targetID'];
			if($data['action'] == 'getData'){
				$result = Model('SourceAuth')->getAuth($info['sourceID']);
				show_json($result);
			}else if($data['action'] == 'clearChildren'){
				//清空所有子文件(夹)的权限；
				$result = Model('SourceAuth')->authClear($info['sourceID']);
			}else if($data['action'] == 'getAllChildren'){
				//该文件夹下所有单独设置过权限的内容; 按层级深度排序-由浅到深(文件夹在前)
				$result = Model('SourceAuth')->getAllChildren($info['sourceID']);
				$result = array_page_split($result);
				show_json($result,true);
			}else if($data['action'] == 'getAllChildrenByUser'){
				//该文件夹下所有针对某用户设置或权限的内容;
				$result = Model('SourceAuth')->getAllChildrenByUser($info['sourceID'],$this->in['userID']);
				$result = array_page_split($result);
				show_json($result,true);
			}else if($data['action'] == 'setAllChildrenByUser'){
				//重置该文件夹下所有针对某用户设置权限的权限;
				$result = Model('SourceAuth')->setAllChildrenByUser($info['sourceID'],$this->in['userID'],$this->in['authID']);
				show_json($result ? LNG('explorer.success'): LNG('explorer.error'),true);
			}else if($data['action'] == 'getGroupUser'){
				//部门成员在该部门的初始权限; 按权限大小排序
				$result = Model('User')->listByGroup($groupID);
				foreach($result['list'] as $index=>$userInfo){
					// $userInfo = Model('User')->getInfoSimpleOuter($userInfo['userID']);
					$groupAuth = array_find_by_field($userInfo['groupInfo'],'groupID',$groupID);
					$userInfo['groupAuth']  = $groupAuth ? $groupAuth['auth'] : false;
					$result['list'][$index] = $userInfo;
				}
				// 按权限高低排序;
				$result['list'] = array_sort_by($result['list'],'groupAuth.auth',true);
				show_json($result,true);
			}else{
				$setAuth = $this->setAuthSelf($info,$data['auth']);
				$result = Model('SourceAuth')->setAuth($info['sourceID'],$setAuth);
			}
		}
		$msg = !!$result ? LNG('explorer.success') : LNG('explorer.error');
		show_json($msg,!!$result);
	}
	
	// 设置权限.默认设置自己为之前管理权限; 如果只有自己则清空;
	private function setAuthSelf($pathInfo,$auth){
		if(!$auth) return $auth;
		$selfAuth = _get($pathInfo,'auth.authInfo.id');
		$authList = array();
		foreach($auth as $item){
			if( $item['targetID'] == USER_ID && 
				$item['targetType'] == SourceModel::TYPE_USER){
				continue;
			}
			$authList[] = $item;
		}
		if(!$authList || !$selfAuth) return $authList;
		$authList[] = array(
			'targetID' 	=> USER_ID, 
			'targetType'=> SourceModel::TYPE_USER,
			'authID' 	=> $selfAuth
		);
		return $authList;
	}
	
	public function pathAllowCheck($path){
		$notAllow = array('/', '\\', ':', '*', '?', '"', '<', '>', '|');
		$parse = KodIO::parse($path);
		if($parse['pathBase']){
			$path = $parse['param'];
		}
		$name = get_path_this($path);
		$checkName = str_replace($notAllow,'_',$name);
		if($name != $checkName){
		    show_json(LNG('explorer.charNoSupport').implode(',',$notAllow),false);
		}
		
		$maxLength = $GLOBALS['config']['systemOption']['fileNameLengthMax'];
		if($maxLength && strlen($name) > $maxLength ){
			show_json(LNG("common.lengthLimit")." (max=$maxLength)",false);
		}
		return;
	}
	
	public function mkfile(){
		$this->pathAllowCheck($this->in['path'],true);
		$info = IO::info($this->in['path']);
		if($info && $info['type'] == 'file'){ //父目录为文件;
			show_json(LNG('explorer.success'),true,IO::pathFather($info['path']));
		}
		
		$tplPath = BASIC_PATH.'static/others/newfile-tpl/';
		$ext     = get_path_ext($this->in['path']);
		$tplFile = $tplPath.'newfile.'.$ext;
		$content = _get($this->in,'content','');
		if( isset($this->in['content']) ){
			if( _get($this->in,'base64') ){ //文件内容base64;
				$content = base64_decode($content);
			}
		}else if(@file_exists($tplFile)){
			$content = file_get_contents($tplFile);
		}
		$repeat = !empty($this->in['fileRepeat']) ? $this->in['fileRepeat']:REPEAT_RENAME;
		$result = IO::mkfile($this->in['path'],$content,$repeat);
		
		$errorLast = IO::getLastError(LNG('explorer.error'));
		$msg = !!$result ? LNG('explorer.success') : $errorLast;
		show_json($msg,!!$result,$result);
	}
	public function mkdir(){
		$this->pathAllowCheck($this->in['path']);
		$repeat = !empty($this->in['fileRepeat']) ? $this->in['fileRepeat']:REPEAT_SKIP;
		$info = IO::info($this->in['path']);
		if($info && $info['type'] == 'file'){ //父目录为文件;
			show_json(LNG('explorer.success'),true,IO::pathFather($info['path']));
		}
		
		$result = IO::mkdir($this->in['path'],$repeat);
		$errorLast = IO::getLastError(LNG('explorer.error'));
		$msg = !!$result ? LNG('explorer.success') : $errorLast;
		show_json($msg,!!$result,$result);
	}
	public function pathRename(){
		$this->pathAllowCheck($this->in['newName']);
		$path = $this->in['path'];
		$this->taskCopyCheck(array(array("path"=>$path)));
		
		$result = IO::rename($path,$this->in['newName']);
		$errorLast = IO::getLastError(LNG('explorer.pathExists'));
		$msg = !!$result ? LNG('explorer.success') : $errorLast;
		show_json($msg,!!$result,$result);
	}

	public function pathDelete(){
		$list = json_decode($this->in['dataArr'],true);
		$this->taskCopyCheck($list);
		$toRecycle = Model('UserOption')->get('recycleOpen');
		if( _get($this->in,'shiftDelete') == '1' ){
			$toRecycle = false;
		}
		$success=0;$error=0;
		foreach ($list as $val) {
			$result = Action('explorer.recycleDriver')->removeCheck($val['path'],$toRecycle);
			$result ? $success ++ : $error ++;
		}
		$code = $error === 0 ? true:false;
		$errorLast = IO::getLastError(LNG('explorer.removeFail'));
		$msg  = $code ? LNG('explorer.removeSuccess') : $errorLast;
		if(!$code && $success > 0){
			$msg = $success.' '.LNG('explorer.success').', '.$error.' '.$errorLast;
		}
		show_json($msg,$code);
	}
	// 从回收站删除
	public function recycleDelete(){		
		$pathArr   = false;
		if( _get($this->in,'all') ){
			$recycleList = Model('SourceRecycle')->listData();
			foreach ($recycleList as $key => $sourceID) {
				$recycleList[$key] = array("path"=>KodIO::make($sourceID));
			}
			$this->taskCopyCheck($recycleList);//彻底删除: children数量获取为0,只能是主任务计数;
		}else{
			$dataArr = json_decode($this->in['dataArr'],true);
			$this->taskCopyCheck($dataArr);
			$pathArr = $this->parseSource($dataArr);
		}
		Model('SourceRecycle')->remove($pathArr);
		Action('explorer.recycleDriver')->remove($pathArr);

		// 清空回收站时,重新计算大小; 一小时内不再处理;
		Model('Source')->targetSpaceUpdate(SourceModel::TYPE_USER,USER_ID);
		$cacheKey = 'autoReset_'.USER_ID;
		if(isset($this->in['all']) && time() - intval(Cache::get($cacheKey)) > 3600 * 10 ){
			Cache::set($cacheKey,time());
			$USER_HOME = KodIO::sourceID(MY_HOME);
			Model('Source')->folderSizeResetChildren($USER_HOME);
			Model('Source')->userSpaceReset(USER_ID);
		}
		show_json(LNG('explorer.success'));
	}
	//回收站还原
	public function recycleRestore(){
		$pathArr = false;
		if( _get($this->in,'all') ){
			$recycleList = Model('SourceRecycle')->listData();
			foreach ($recycleList as $key => $sourceID) {
				$recycleList[$key] = array("path"=>KodIO::make($sourceID));
			}
			$this->taskCopyCheck($recycleList);
		}else{
			$dataArr = json_decode($this->in['dataArr'],true);
			$this->taskCopyCheck($dataArr);
			$pathArr = $this->parseSource($dataArr);
		}

		Action('explorer.recycleDriver')->restore($pathArr);
		Model('SourceRecycle')->restore($pathArr);
		show_json(LNG('explorer.success')); 
	}
	private function parseSource($list){
		$result = array();
		foreach ($list as $value) {
			$parse = KodIO::parse($value['path']);
			$thePath = $value['path'];// io路径;物理路径;协作分享路径处理保持不变;
			if($parse['type'] == KodIO::KOD_SOURCE){
				$thePath = IO::getPath($value['path']);
			}
			$result[] = $thePath;
		}
		return $result;
	}

	
	public function pathCopy(){
		Session::set(array(
			'pathCopyType'	=> 'copy',
			'pathCopy'		=> $this->in['dataArr'],
		));
		show_json(LNG('explorer.copySuccess'));
	}
	public function pathCute(){
		Session::set(array(
			'pathCopyType'	=> 'cute',
			'pathCopy'		=> $this->in['dataArr'],
		));
		show_json(LNG('explorer.cuteSuccess'));
	}
	public function pathCopyTo(){
		$this->pathPast('copy',$this->in['dataArr']);	
	}
	public function pathCuteTo(){
		$this->pathPast('cute',$this->in['dataArr']);	
	}
	public function clipboard(){
		if(isset($this->in['clear'])){
			Session::set('pathCopy', json_encode(array()));
			Session::set('pathCopyType','');
			return;
		}
		$clipboard = json_decode(Session::get('pathCopy'),true);
		if(!$clipboard){
			$clipboard = array();
		}
		show_json($clipboard,true,Session::get('pathCopyType'));
	}
	public function pathLog(){
		$info = IO::info($this->in['path']);
		if(!$info['sourceID']){
			show_json('path error',false);
		}
		$data = Model('SourceEvent')->listBySource($info['sourceID']);
		
		// 协作分享;路径数据处理;
		if($info['shareID']){
			$shareInfo	= Model('Share')->getInfo($info['shareID']);
			$userActon  = Action('explorer.userShare');
			foreach($data['list'] as $i=>$item){
				if($item['sourceInfo']){
					$data['list'][$i]['sourceInfo'] = $userActon->_shareItemeParse($item['sourceInfo'],$shareInfo);
				}
				if($item['parentInfo']){
					$data['list'][$i]['parentInfo'] = $userActon->_shareItemeParse($item['parentInfo'],$shareInfo);
				}
				if(!is_array($item['desc'])){continue;}
				if(is_array($item['desc']['from'])){
					$data['list'][$i]['desc']['from'] = $userActon->_shareItemeParse($item['desc']['from'],$shareInfo);
				}
				if(is_array($item['desc']['to'])){
					$data['list'][$i]['desc']['to'] = $userActon->_shareItemeParse($item['desc']['to'],$shareInfo);
				}
				if(is_array($item['desc']['sourceID'])){
					$data['list'][$i]['desc']['sourceID'] = $userActon->_shareItemeParse($item['desc']['sourceID'],$shareInfo);
				}
			}
		}
		show_json($data);
	}

	/**
	 * 复制或移动
	 */
	public function pathPast($copyType=false,$list=false){
		if(!$copyType){
			$copyType = Session::get('pathCopyType');
			$list     = Session::get('pathCopy');
			if($copyType == 'cute'){
				Session::set('pathCopy', json_encode(array()));
				Session::set('pathCopyType', '');
			}
		}

		$list = json_decode($list,true);
		$list = is_array($list) ? $list : array();
		if($copyType == 'copy'){
			$list = $this->copyCheckShare($list);
		}
		$pathTo = $this->in['path'];
		if (count($list) == 0 || !$pathTo) {
			show_json(LNG('explorer.clipboardNull'),false);
		}
		ignore_timeout(0);
		$this->taskCopyCheck($list);
		
		Hook::trigger('explorer.pathCopyMove',$copyType,$list);
		$repeat = Model('UserOption')->get('fileRepeat');
		$repeat = !empty($this->in['fileRepeat']) ? $this->in['fileRepeat'] :$repeat;
		$result = array();$errorList = array();
		
		// 所有操作中,是否有重名覆盖的情况(文件,文件夹都算)
		$infoMore = array('hasExistAll'=>false,'pathTo'=>$pathTo,'listFrom'=>$list,'listTo'=>array());
		for ($i=0; $i < count($list); $i++) {
			$thePath = $list[$i]['path'];
			$repeatType = $repeat;
			$ioInfo 	= IO::info($thePath);
			$driverTo 	= IO::init($this->in['path']);
			$hasExists  = $driverTo->fileNameExist($driverTo->path,$ioInfo['name']);

			if($copyType == 'copy') {
				//复制到自己所在目录,则为克隆;
				$driver = IO::init($thePath);
				$father = $driver->getPathOuter($driver->pathFather($driver->path));
				if(KodIO::clear($father) == KodIO::clear($pathTo) ){
					$repeatType = REPEAT_RENAME_FOLDER;
				}
				$itemResult = IO::copy($thePath,$pathTo,$repeatType);
			}else{
				$itemResult = IO::move($thePath,$pathTo,$repeatType);
			}
			
			// 复制/移动时; 所有内容是否存在文件夹已存在覆盖,文件已存在覆盖的情况; 存在时前端不支持撤销操作;
			if($hasExists){
				if($ioInfo['type'] == 'file' && ($repeatType != REPEAT_RENAME && $repeatType != REPEAT_RENAME_FOLDER)){
					$infoMore['hasExistAll'] = true;
				}
				if($ioInfo['type'] == 'folder' && $repeatType != REPEAT_RENAME_FOLDER){
					$infoMore['hasExistAll'] = true;
				}
			}
			if(!$itemResult){$errorList[] = $thePath;continue;}
			$result[] = $itemResult;
			$infoMore['listTo'][] = array('path'=>$itemResult);
		}
		$code = $result ? true:false;
		$msg  = $copyType == 'copy'?LNG('explorer.pastSuccess'):LNG('explorer.cutePastSuccess');
		
		if(count($result) == 0){$msg = LNG('explorer.error');}
		if($errorList){$msg .= "(".count($errorList)." error)\n".IO::getLastError();}
		show_json($msg,$code,$result,$infoMore);
	}
	
	// 外链分享复制;
	private function copyCheckShare($list){
		for ($i=0; $i < count($list); $i++) {
			$path = $list[$i]['path'];
			$pathParse= KodIO::parse($path);
			if($pathParse['type'] != KodIO::KOD_SHARE_LINK) continue;
			
			// 外链分享处理; 权限限制相关校验; 关闭下载--不支持转存; 转存数量限制处理;
			$info = Action('explorer.share')->sharePathInfo($path);
			if(!$info){
				show_json($GLOBALS['explorer.sharePathInfo.error'], false);
			}
			if($info['option'] && $this->share['options']['notDownload'] == '1'){
				show_json(LNG('explorer.share.noDownTips'), false);
			}
			$list[$i]['path'] = $info['path'];
		}
		return $list;
	}

	// 文件移动; 耗时任务;
	private function taskCopyCheck($list){
		$list = is_array($list) ? $list : array();
		$defaultID = 'copyMove-'.USER_ID.'-'.rand_string(8);
		$taskID = $this->in['longTaskID'] ? $this->in['longTaskID']:$defaultID;
		
		$task = new TaskFileTransfer($taskID,'copyMove');
		$task->update(0,true);//立即保存, 兼容文件夹子内容过多,扫描太久的问题;
		for ($i=0; $i < count($list); $i++) {
			$task->addPath($list[$i]['path']);
		}
	}
	
	/**
	 * 压缩下载
	 */
	public function fileDownloadRemove(){
		$path = Input::get('path', 'require');
		$path = $this->pathCrypt($path,false);
		if(!$path || !IO::exist($path)) {
			show_json(LNG('common.pathNotExists'), false);
		}
		IO::fileOut($path,true);
		$dir = get_path_father($path);
		if(strstr($dir,TEMP_FILES)){
		    del_dir($dir);
		}
	}

	private function tmpZipName($dataArr){
		$files = array();
		foreach($dataArr as $item){
			$info	 = IO::info($item['path']);
			$files[] = IOArchive::tmpFileName($info);
		}
		sort($files);
		return md5(json_encode($files));
	}

	public function clearCache(){
		$maxTime = 3600*24;
		$list = IO::listPath(TEMP_FILES);
		$list = is_array($list) ? $list : array('fileList'=>array(),'folderList'=>array());
		$list = array_merge($list['fileList'],$list['folderList']);
		foreach($list as $item){
			if(time() - $item['modifyTime'] < $maxTime) continue;
			if(is_dir($item['path'])){
				del_dir($item['path']);
			}else{
				del_file($item['path']);
			}
		}
	}
	/**
	 * 多文件、文件夹压缩下载
	 * @return void
	 */
	public function zipDownload(){	
		$dataArr  = json_decode($this->in['dataArr'],true);
		// 前端压缩处理;
		if($this->in['zipClient'] == '1'){
			return show_json($this->zipDownloadClient($dataArr),true);
		}
		
		ignore_timeout();
		$zipFolder = $this->tmpZipName($dataArr);
		$zipCache  = TEMP_FILES;mk_dir($zipCache);

		$zipPath = Cache::get($zipFolder);
		if($zipPath && IO::exist($zipPath) ){
			return $this->zipDownloadStart($zipPath);
		}

		$zipPath = $this->zip($zipCache.$zipFolder . '/');
		Cache::set($zipFolder, $zipPath, 3600*6);
		$this->zipDownloadStart($zipPath);
	}
	private function zipDownloadStart($zipPath){
		if(isset($this->in['disableCache']) && $this->in['disableCache'] == '1'){
			if(!$zipPath || !IO::exist($zipPath)) return;
			IO::fileOut($zipPath,true);
			return;
		}
		show_json(LNG('explorer.zipSuccess'),true,$this->pathCrypt($zipPath));
	}
	
	// 文件名加解密
	public function pathCrypt($path, $en=true){
		$pass = Model('SystemOption')->get('systemPassword').'encode';
		return $en ? Mcrypt::encode($path,$pass) : Mcrypt::decode($path,$pass);
	}
	
	public function zipDownloadClient($dataArr){
		$result  = array();
		foreach($dataArr as $itemZip){
			$pathInfo   = IO::info($itemZip['path']);
			$isFolder   = $itemZip['type'] == 'folder';
			$itemZipOut = array('path'=>'/'.$itemZip['name'],'folder'=>$isFolder);
			$itemZipOut['modifyTime'] = $pathInfo['modifyTime'];

			if(!$isFolder){
				$itemZipOut['filePath'] = $itemZip['path'];
				$itemZipOut['size'] = $pathInfo['size'];				
				$result[] = $itemZipOut;continue;
			}
			$result[] = $itemZipOut;
			$children = IO::listAllSimple($itemZip['path']);
			$result   = array_merge($result, $children);
		}
		return $result;
	}

	/**
	 * 压缩
	 * @param string $zipPath
	 */
	public function zip($zipPath=''){
		ignore_timeout();
		$dataArr  = json_decode($this->in['dataArr'],true);
		$zipLimit = Model('SystemOption')->get('downloadZipLimit');
		$task 	  = $this->taskZip($dataArr);
		if($zipLimit && $zipLimit > 0){
			$zipLimit  = floatval($zipLimit) * 1024 * 1024 * 1024;
			$totalSize = intval($task->task['sizeTotal']);
			if($totalSize > $zipLimit){
				$limitTips = '('.size_format($zipLimit).')';
				show_json(LNG('admin.setting.downloadZipLimitTips').$limitTips,false);
			}
		}
		
		$fileType = Input::get('type', 'require','zip');
		$repeat   = Model('UserOption')->get('fileRepeat');
		$repeat   = !empty($this->in['fileRepeat']) ? $this->in['fileRepeat'] :$repeat;

		$zipFile = IOArchive::zip($dataArr, $fileType, $zipPath,$repeat);
		if($zipPath != '') return $zipFile;
		$info = IO::info($zipFile);
		$data = LNG('explorer.zipSuccess').LNG('explorer.file.size').":".size_format($info['size']);
		show_json($data,true,$zipFile);
	}
	
	private function taskZip($list){
		$list = is_array($list) ? $list : array();
		$defaultID = 'zip-'.USER_ID.'-'.rand_string(8);
		$taskID = $this->in['longTaskID'] ? $this->in['longTaskID']:$defaultID;
		$task = new TaskZip($taskID,'zip');
		$task->update(0,true);//立即保存, 兼容文件夹子内容过多,扫描太久的问题;
		for ($i=0; $i < count($list); $i++) {
			$task->addPath($list[$i]['path']);
		}
		return $task;
	}
	private function taskUnzip($data){
		$defaultID = 'unzip-'.USER_ID.'-'.rand_string(8);
		$taskID = $this->in['longTaskID'] ? $this->in['longTaskID']:$defaultID;
		$task = new TaskUnzip($taskID,'zip');
		$task->addFile($data['path']);
	}
	
	/**
	 * 解压缩
	 */
	public function unzip(){
		ignore_timeout();
		$data = Input::getArray(array(
			'path' => array('check' => 'require'),
			'pathTo' => array('check' => 'require'),
			'unzipPart' => array('check' => 'require', 'default' => '-1')
		));
		
		$repeat = Model('UserOption')->get('fileRepeat');
		$repeat = !empty($this->in['fileRepeat']) ? $this->in['fileRepeat'] :$repeat;
		$this->taskUnzip($data);
		IOArchive::unzip($data,$repeat);
		show_json(LNG('explorer.unzipSuccess'));
	}

	/**
	 * 查看压缩文件列表
	 */
	public function unzipList(){
		$data = Input::getArray(array(
			'path' => array('check' => 'require'),
			'index' => array('check' => 'require', 'default' => '-1'),
			'download' => array('check' => 'require', 'default' => false),
			'name' => array('check' => 'require', 'default' => ''),
		));
		$this->taskUnzip($data);
		$list = IOArchive::unzipList($data);
		show_json($list);
	}

	public function fileDownload(){
		$this->in['download'] = 1;
		$this->fileOut();
	}
	//输出文件
	public function fileOut(){
		$path = $this->in['path'];
		if(!$path) return; 
		$isDownload = isset($this->in['download']) && $this->in['download'] == 1;
		if($isDownload && !Action('user.authRole')->authCanDownload()){
			show_json(LNG('explorer.noPermissionAction'),false);
		}
		if ($isDownload) Hook::trigger('explorer.fileDownload', $path);
		Hook::trigger('explorer.fileOut', $path);
		if(isset($this->in['type']) && $this->in['type'] == 'image'){
			$info = IO::info($path);
			$imageThumb = array('jpg','png','jpeg','bmp');
			$width = isset($this->in['width']) ? intval($this->in['width']) :0;
			if(!$width || $width >= 2000){
				$this->updateLastOpen($path);
			}
			if($info['size'] >= 1024*200 &&
				function_exists('imagecolorallocate') &&
				in_array($info['ext'],$imageThumb) 
			){
				return IO::fileOutImage($path,$width);
			}
		}
		$this->updateLastOpen($path);
		IO::fileOut($path,$isDownload);
	}
	/*
	相对某个文件访问其他文件; 权限自动处理;支持source,分享路径,io路径,物理路径;
	path={source:1138926}/&add=images/as.png; path={source:1138926}/&add=as.png
	path={shareItem:123}/1138934/&add=images/as.png
	*/
	public function fileOutBy(){
		if(!$this->in['path']) return; 
		
		// 拼接转换相对路径;
		$io = IO::init($this->in['path']);
		$parent = $io->getPathOuter($io->pathFather($io->path));
		$find   = $parent.'/'.rawurldecode($this->in['add']); //支持中文空格路径等;
		$find   = KodIO::clear(str_replace('./','/',$find));
		$info   = IO::infoFull($find);
		if(!$info || $info['type'] != 'file'){
			return show_json(LNG('common.pathNotExists'),false);
		}

		$dist = $info['path'];
		ActionCall('explorer.auth.canView',$dist);// 再次判断新路径权限;
		$this->updateLastOpen($dist);
		Hook::trigger('explorer.fileOut', $dist);
		IO::fileOut($dist,false);
	}
	
	/**
	 * 打开自己的文档；更新最后打开时间
	 */
	public function updateLastOpen($path){
		$driver = IO::init($path);
		if($driver->pathParse['type'] != KodIO::KOD_SOURCE) return;

		$sourceID = $driver->pathParse['id'];
		$sourceInfo = $this->model->sourceInfo($sourceID);
		if( $sourceInfo['targetType'] == SourceModel::TYPE_USER && 
			$sourceInfo['targetID'] == USER_ID ){
			$data = array('viewTime' => time());
			$this->model->where(array('sourceID'=>$sourceID))->save($data);
		}
	}
	
	//通用保存
	public function fileSave(){
		if(!$this->in['path'] || !$this->in['path']) return; 
		$result = IO::setContent($this->in['path'],$this->in['content']);
		Hook::trigger("explorer.fileSaveStart",$this->in['path']);
		show_json($result,!!$result);
	}
	//通用预览
	public function fileView(){
	}

	//通用缩略图
	public function fileThumb(){
		Hook::trigger("explorer.fileThumbStart",$this->in['path']);
	}	
}