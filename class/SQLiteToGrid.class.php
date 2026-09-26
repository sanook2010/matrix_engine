<?php
// +------------------------------------------------------------------------+
// | SQLiteToGrid (Fixed Block 256x256 Layout)                              |
// +------------------------------------------------------------------------+
// | Copyright (c) 2000-2003 Frédéric HENNINOT                              |
// | Email         fhenninot@freesurf.fr                                    |
// | Licence       This code is released under GPL                          |
// +------------------------------------------------------------------------+

/**
 * PHP4 Grid Presentation Data - Fixed Block 256x256 Grid Layout.
 * @package SQLiteManager
 * @author Frédéric HENNINOT <fhenninot@freesurf.fr>
 * @version $Id: SQLiteToGrid.class.php,v 1.41 2006/04/14 15:16:52 freddy78 Exp $ $Revision: 1.41 $
 */

class SQLiteToGrid {

	var $SQLiteConnId;
	var $query;
	var $queryCount;
	var $listChamp;
	var $queryOrderDefault;
	var $nbRecordQuery;
	var $order;
	var $orderInit;
	var $recordPerPage;
	var $realQuery;
	var $title;
	var $tabId;
	var $sort;
	var $buttonStyle=true;
	var $getVar;
	var $data;
	var $style;
	var $align;
	var $format;
	var $hide;
	var $calcColumn;
	var $nbColonne;
	var $width;
	var $navigate=false;
	var $nbPage;
	var $pageStart;
	var $infoNav;
	var $oldOrder;
	var $orderSens;
	var $out;
	var $tabCaption;
	var $onClick;

	function __construct(&$connId, $query, $tabId='', $autoTitle=true, $nbRecord=10, $width=500){
		if(is_resource($connId) || is_object($connId)) $this->SQLiteConnId = $connId;
		if($tabId) $this->tabId = $tabId;
		else $this->setTabId();
		$this->_fromSession();
		$posEnd = strrpos(trim($query), ';');
		if($posEnd) $query = substr(trim($query), 0, $posEnd);
		$this->query = $query;
		$this->onClick = true;
		$this->recordPerPage = $nbRecord;
		$this->navigate = true;
		$this->_parseQuery($autoTitle);
		$this->_definePage();
		if($width) $this->width = $width;
		if(empty($this->tabId)) $this->setTabId();
		$data = $this->_getRecord();
		if(is_array($data)){
			foreach($data as $ligne){
				if(empty($this->nbColonne)) {
					$this->nbColonne = count($ligne);
				} elseif(count($ligne)!=$this->nbColonne) {
					$this->_sendError($GLOBALS['traduct']->get(105));
					return;
				}
			}
			$this->data = $data;
		} else {
			$this->_sendError($GLOBALS['traduct']->get(106));
		}
		$this->_toSession();
		return true;
	}

	function setTabId($ident=''){
		if(!empty($ident)) {
			$this->tabId = $GLOBALS['GridTabId'][] = $ident;
		} else {
			$tabIndex = $GLOBALS['GridTabId'];
			if(!is_array($tabIndex)) {
				$this->tabId = $GLOBALS['GridTabId'][]='tab1';
			} else {
				$max = 0;
				foreach($tabIndex as $value){
					if((substr($value, 0, 3) == 'tab') && (($num = substr($value, 3, strlen($value)-3))>$max)) $max = $num;
				}
				$this->tabId = $GLOBALS['GridTabId'][] = 'tab'.($max+1);
			}
		}
		return;
	}

	function setTitle($title){
		if(is_array($title)) $this->title = $title;
		if(empty($this->nbColonne)) $this->nbColonne = count($title);
	}

	function setAlign($tabAlign){
		if(is_array($tabAlign)){
			if(count($tabAlign)!=$this->nbColonne) {
				$this->_sendError($GLOBALS['traduct']->get(107));
			} else {
				$this->align = $tabAlign;
			}
		}
	}

	function setFormat($tabFormat){
		if(is_array($tabFormat)){
			$this->format = $tabFormat;
		}
	}

	function setGetVars($string){
		$this->getVar = $string;
		return;
	}

	function setSort($tabSort){
		if(is_array($tabSort)){
			$this->sort = $tabSort;
		}
	}

	function enableSortStyle($button = true){
		$this->buttonStyle = $button ? true : false;
		return;
	}

	function disableNavBarre(){
		$this->navigate = false;
	}

	function hideColumn($num){
		if(is_array($num)) $this->hide = $num;
		else $this->hide[$num] = true;
	}

	function showColumn($num){
		if(is_array($num)) $this->hide = $num;
		else $this->hide[$num] = false;
	}

	function addCalcColumn($title, $format, $align, $pos=999){
		if(!empty($title) && !empty($format)){
			$numCalc = !is_array($this->calcColumn) ? 0 : count($this->calcColumn);
			$this->calcColumn[$numCalc]['title'] 	= $title;
			$this->calcColumn[$numCalc]['format'] 	= $format;
			$this->calcColumn[$numCalc]['align'] 	= $align;
			$this->calcColumn[$numCalc]['position'] = $pos;
		}
	}

	function build(){
		$out = '';
		$out .= $this->_showHeader();
		$out .= $this->_showTable();
		if($this->navigate) $out .= $this->_showNavigate();
		$out .= $this->_showFooter();
		$this->out = $out;
		return $out;
	}

	function show(){
		echo $this->out;
		return;
	}

	function _sendError($message){
		echo '<div style="width: 300px; border: 2px solid red; padding: 10px; text-align: center;">';
		echo '<span style="font-size: 16px; color: red;"><b>'.$GLOBALS['traduct']->get(9).'</b></span><br>';
		echo '<span style="font-size: 14px; color: blue;"><b>'.$message.'</b></span>';
		echo '</div>';
		return;
	}

	function _showHeader(){
		$out = '<!-- SQLiteToGrid.class.php : _showHeader() (256x256 Block Grid) -->'."\n";
		$out .= "<div class=\"sqlite-grid-container ".$this->tabId."\">\n";
		
		if(isset($this->tabCaption) && !empty($this->tabCaption)) {
			$out .= "<div class=\"grid-caption\" style=\"margin-bottom: 8px; font-weight: bold;\">".$this->tabCaption["content"]."</div>";
		}

		$out .= "<div class=\"grid-sort-bar\" style=\"margin-bottom: 12px; padding: 6px; background: #f1f1f1; font-size: 12px;\"><span>เรียงตาม: </span>";
		if(empty($this->getVar)) $this->getVar = '?';
		else $this->getVar .= '&amp;';
		
		if (count($this->title)) {
			foreach($this->title as $index => $titleColonne) {
				if(!is_array($this->hide) || (!isset($this->hide[$index]) || !$this->hide[$index])){
					$infoSort = "";
					if((isset($_GET["sort".$this->tabId]) && ($_GET["sort".$this->tabId] == $index)) || (isset($this->orderInit) && ($this->orderInit==$index))){
						$infoSort = ($this->orderSens == "ASC") ? ' ▲' : ' ▼';
					}
					$out .= "<a href=\"".$this->getVar."sort".$this->tabId."=".$index."\" style=\"margin-right: 12px; text-decoration: none;\"><strong>".$titleColonne."</strong>".$infoSort."</a>";
				}
			}
		}
		$out .= "</div>\n";
		
		// กำหนด CSS Grid ให้บล็อกการ์ดมีขนาดกว้าง x สูง = 256px x 256px เป๊ะๆ
		$out .= "<div class=\"grid-blocks-wrapper\" style=\"display: grid; grid-template-columns: repeat(auto-fill, minmax(256px, 1fr)); gap: 15px;\">\n";
		return $out;
	}

	function _showTable(){
		$out = '<!-- SQLiteToGrid.class.php : _showTable() (256x256 Cards) -->'."\n";
		$pos = 0;
		if(is_array($this->data)) {
			foreach($this->data as $ligne){
				// ล็อกขนาดความกว้างและความสูงตายตัวที่ 256x256 พิกเซล พร้อมซ่อนข้อความที่ล้นกล่อง (overflow)
				$out .= "<div class=\"grid-card-item\" style=\"width: 256px; height: 256px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 6px; padding: 12px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;\">\n";
				
				$contentOut = "<div class=\"card-inner-content\" style=\"overflow-y: auto; flex-grow: 1; padding-right: 4px;\">";
				while(list($index, $value) = each($ligne)){
					if($GLOBALS["allHTML"]) $value = htmlentities($value, ENT_NOQUOTES, $GLOBALS['charset']);
					if(!$GLOBALS["allFullText"]){
						if(strlen($value)>PARTIAL_TEXT_SIZE) $value = substr($value, 0, PARTIAL_TEXT_SIZE).'...';
					}
					if($value=="") {
						if(isset($this->NullInfo[$this->title[$index]]) && ($this->NullInfo[$this->title[$index]]==0)) $value="<i>NULL</i>";
						else $value="&nbsp;";
					}

					if(!is_array($this->hide) || !isset($this->hide[$index]) || !$this->hide[$index]) {
						$colTitle = isset($this->title[$index]) ? $this->title[$index] : "Field $index";
						$displayVal = (!empty($this->format[$index])) ? $this->_formatCalc($ligne, $this->format[$index]) : $value;
						
						$contentOut .= "<div class=\"card-field-row\" style=\"margin-bottom: 4px; font-size: 12px; word-break: break-all;\">";
						$contentOut .= "<span class=\"card-field-label\" style=\"color: #666; font-weight: bold;\">{$colTitle}: </span>";
						$contentOut .= "<span class=\"card-field-val\">{$displayVal}</span>";
						$contentOut .= "</div>\n";
					}
				}

				if(is_array($this->calcColumn)) {
					foreach($this->calcColumn as $calcCol){
						$contentOut .= "<div class=\"card-calc-row\" style=\"margin-top: 6px; border-top: 1px dashed #eee; padding-top: 4px; font-size: 12px;\">";
						$contentOut .= "<strong>{$calcCol['title']}:</strong> ".$this->_formatCalc($ligne, $calcCol['format'], $pos);
						$contentOut .= "</div>\n";
					}
				}
				$contentOut .= "</div>\n"; // ปิด card-inner-content

				$out .= $contentOut;
				$out .= "<div class=\"card-footer-info\" style=\"font-size: 10px; color: #999; text-align: right; border-top: 1px solid #f0f0f0; padding-top: 4px;\">#{$pos}</div>\n";
				$out .= "</div>\n"; // ปิด grid-card-item
				$pos++;
			}
		}
		return $out;
	}

	function _showFooter(){
		return "</div>\n</div>\n";
	}

	function _showNavigate(){
		$out = '<!-- SQLiteToGrid.class.php : _showNavigate() -->'."\n";
		$out .= "<div class=\"grid-navigation-bar\" style=\"margin-top: 20px; padding: 10px; background: #f9f9f9; text-align: center; border-radius: 4px;\">\n";
		
		$top = NAV_TOP ? '<img src="'.NAV_TOP.'" border=0>' : '<<';
		$prec = NAV_PREC ? '<img src="'.NAV_PREC.'" border=0>' : '<';
		$suiv = NAV_SUIV ? '<img src="'.NAV_SUIV.'" border=0>' : '>';
		$end = NAV_END ? '<img src="'.NAV_END.'" border=0>' : '>>';
		
		$linkSort = isset($_GET['sort'.$this->tabId]) ? 'sort'.$this->tabId.'='.$_GET['sort'.$this->tabId].'&amp;' : '';
		
		if($this->pageStart>1) {
			$top = "<a href=\"".$this->getVar.$linkSort."page".$this->tabId."=1\">".$top."</a>";
			$prec = "<a href=\"".$this->getVar.$linkSort."page".$this->tabId."=".($this->pageStart - 1)."\">".$prec."</a>";
		}
		if($this->pageStart<$this->nbPage){
			$suiv = "<a href=\"".$this->getVar.$linkSort."page".$this->tabId."=".($this->pageStart + 1)."\">".$suiv."</a>";
			$end = "<a href=\"".$this->getVar.$linkSort."page".$this->tabId."=".($this->nbPage)."\">".$end."</a>";
		}
		
		if($this->nbPage<NAV_NBLINK){
			$startLink = 1;
			$endLink = $this->nbPage;
		} else {
			if(($this->pageStart<($this->nbPage - (int)(NAV_NBLINK/2))) && ($this->pageStart>(int)(NAV_NBLINK/2))) $startLink = $this->pageStart - ((int)(NAV_NBLINK/2));
			elseif($this->pageStart>=($this->nbPage - (int)(NAV_NBLINK/2))) $startLink = $this->nbPage - (NAV_NBLINK-1);
			else $startLink = 1;
			if( ($startLink+NAV_NBLINK-1) > $this->nbPage) {
				$startLink = $this->nbPage - NAV_NBLINK + 1;
				$endLink = $this->nbPage;
			} else {
				$endLink = $startLink + (NAV_NBLINK-1);
			}
		}
		
		$link = '';
		for($i=$startLink ; $i<=$endLink ; $i++){
			if($i == $this->pageStart) $link .= '<span style="font-size: 12px; font-weight: bold; padding: 0 4px;">['.$i.']</span>';
			else $link .= "<a href=\"".$this->getVar.$linkSort."page".$this->tabId."=".$i."\"><span style='font-size: 12px; padding: 0 4px;'>".$i."</span></a>";
			if($i < $endLink) $link .= NAV_SEP;
		}
		
		$infoNav = $GLOBALS["traduct"]->get(136)." ".$this->infoNav["start"]."-".$this->infoNav["end"]."/".$this->infoNav["all"];
		$out .= "<div style=\"display: inline-block;\">".$infoNav.NAV_SEP.$top.NAV_SEP.$prec.NAV_SEP.$link.NAV_SEP.$suiv.NAV_SEP.$end."</div>";
		$out .= "</div>\n";
		return $out;
	}

	function _formatCalc(&$ligne, $format, $pos=""){
		preg_match('/#%(.*)%#/', $format, $var);
		while(isset($var[1])){
			if((substr($var[1],0,3)!='POS') && (substr($var[1],0,5)!='QUERY')){
				$format = str_replace('#%'.$var[1].'%#', $ligne[$var[1]], $format);
			} elseif(substr($var[1],0,3)=='POS'){
				$format = str_replace('#%POS%#', $pos, $format);
			} elseif(substr($var[1],0,5)=='QUERY'){
				$format = str_replace('#%QUERY%#', urlencode($this->getRealQuery()), $format);
			}
			preg_match('/#%(.*)%#/', $format, $var);
		}
		return $format;
	}

	function _definePage(){
		$nbRecord = $this->_countRecord();
		$this->nbPage = ceil($nbRecord / $this->recordPerPage);
		$this->pageStart = !isset($_GET['page'.$this->tabId]) ? 1 : $_GET['page'.$this->tabId];
		$this->indexStart = (($this->pageStart - 1) * $this->recordPerPage);
		$this->infoNav['start'] = $this->indexStart;
		$this->infoNav['end']	= $this->indexStart + $this->recordPerPage;
		$this->infoNav['all']	= $nbRecord;
		if($this->infoNav['end']>$nbRecord) $this->infoNav['end']=$nbRecord;
	}

	function _checkOrder(){
		if(isset($_GET['sort'.$this->tabId]) && ($_GET['sort'.$this->tabId]==$this->oldOrder) && (!isset($_GET['page'.$this->tabId]))){
			$this->orderSens = ($this->orderSens == 'ASC') ? 'DESC' : 'ASC';
		} elseif(!isset($_GET['page'.$this->tabId])){
			$this->orderSens = 'ASC';
		}
	}

	function _fromSession(){
		if( array_key_exists('old_order'.$this->tabId, $_SESSION) ) {
			$this->oldOrder = $_SESSION['old_order'.$this->tabId];
		}
		if( array_key_exists('order_sens'.$this->tabId, $_SESSION) ) {
			$this->orderSens = $_SESSION['order_sens'.$this->tabId];
		}
		return;
	}

	function _toSession(){
		$oldOrder = isset($_GET['sort'.$this->tabId]) ? $_GET['sort'.$this->tabId] : (isset($this->orderInit) ? $this->orderInit : '');
		$_SESSION['old_order'.$this->tabId] = $oldOrder;
		$_SESSION['order_sens'.$this->tabId] = $this->orderSens;
		return;
	}

	function _countVisibleColumn(){
		$nbHide = is_array($this->hide) ? array_sum($this->hide) : 0;
		$nbCalc = is_array($this->calcColumn) ? count($this->calcColumn) : 0;
		return ($this->nbColonne - $nbHide + $nbCalc);
	}

	function _parseQuery($autoTitle){
		$this->query = preg_replace('#^select[[:space:]]#i', 'SELECT ', $this->query);
		$this->query = preg_replace('#[[:space:]]from[[:space:]]#i', ' FROM ', $this->query);
		if($autoTitle){
			$queryCalc = str_replace('DISTINCT', ' ', $this->query);
			preg_match('/SELECT[[:space:]](.*)[[:space:]]FROM/i', $queryCalc, $listChamp);
			$this->listChamp = isset($listChamp[1]) ? $listChamp[1] : '';
			
			preg_match('/ORDER[[:space:]]+BY[[:space:]]+(.*)/i', $this->query, $order);
			if(isset($order[0])) $this->query = str_replace($order[0], '', $this->query);
			if(isset($order[1]) && (preg_match('#asc#i', $order[1]) || preg_match('#desc#i', $order[1]))){
				preg_match('/[[:space:]]+(.*)/', trim($order[1]), $sens);
				$order[1] = trim(str_replace($sens, '', $order[1]));
				$this->queryOrderSensDefault = trim($sens[1]);
			}
			if(isset($order[1])) $this->queryOrderDefault = str_replace('"', '', $order[1]);

			$tabTitle = $this->_fetchField();
			$this->setTitle($tabTitle);
		}
		preg_match('/FROM[[:space:]]+(.*)/i', $this->query, $from);
		$this->queryCount = isset($from[1]) ? 'SELECT count(*) FROM '.$from[1] : $this->query;
		
		if(isset($_GET['sort'.$this->tabId])){
			foreach($tabTitle as $index => $name){
				if($index == $_GET['sort'.$this->tabId]) $this->order = $name;
			}
		} elseif(!empty($this->queryOrderDefault)) {
			$this->order = $this->queryOrderDefault;
			$this->orderInit = array_search(trim($this->order), $tabTitle);
		}
		$this->_checkOrder();
		return;
	}

	function getNbRecord(){
		return $this->nbRecordQuery;
	}

	function getRealQuery(){
		return $this->realQuery;
	}

	function _fetchField(){
		$queryLoc = (preg_match('#^select#i', $this->query) && !preg_match('#limit#i', $this->query)) ? $this->query.' LIMIT 0,1' : $this->query;
		if($res = $this->SQLiteConnId->query($queryLoc)){
			for($i=0 ; $i < $this->SQLiteConnId->num_fields() ; $i++){
				$title[] = $this->SQLiteConnId->field_name(null, $i);
			}
			return isset($title) ? $title : false;
		}
		return false;
	}

    function _countRecord(){
        if(!isset($this->nbRecordQuery)){
            $qCount = ($this->SQLiteConnId->getVersion()==2) ? preg_match('/^\s*(UPDATE|DELETE|INSERT|ALTER|JOIN|GROUP|LIMIT|PRAGMA)\s/i', $this->query) : false;
            if($qCount){
                if($this->SQLiteConnId->query($this->queryCount)){
                    $this->nbRecordQuery = $this->SQLiteConnId->fetch_single();
                } else $this->_sendError($GLOBALS['traduct']->get(117));
            } else {
                if (preg_match('#^SELECT \* FROM#i', $this->query)) {
                    $q = preg_replace('#^SELECT \* FROM#i','SELECT COUNT(*) as count FROM', $this->query);
                    if ($this->SQLiteConnId->query($q)) {
                        $this->nbRecordQuery = $this->SQLiteConnId->fetch_single();
                    }
                } else {
                    $tabResult = $this->SQLiteConnId->array_query($this->query);
                    $this->nbRecordQuery = count($tabResult);
                }
            }
        }
        return $this->nbRecordQuery;
    }

	function _getRecord(){
		$order = strpos(trim($this->order), ' ') ? '"'.$this->order.'"' : $this->order;
		$query = $this->query.(($this->order)? ' ORDER BY '.$order.' '.$this->orderSens : '' );
		if(!preg_match('#pragma#i', $this->query) && !preg_match('#limit#i', $this->query)) $query .= ' LIMIT '.$this->indexStart.', '.$this->recordPerPage;

		if($this->SQLiteConnId->query($query)){
			$tabRecord = array();
			while($ligne = $this->SQLiteConnId->fetch_array(null, SQLITE_NUM)){
				$tabRecord[] = $ligne;
			}
		}
		$this->realQuery = $query;
		return $tabRecord;
	}

	function addCaption($align, $content){
		$this->tabCaption['align'] = $align;
		$this->tabCaption['content'] = $content;
	}

	function disableOnClick(){
		$this->onClick = false;
		return;
	}
}
?>
