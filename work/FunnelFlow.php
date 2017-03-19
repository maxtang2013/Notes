<?php
/**
 * Created by PhpStorm.
 * User: maxtang
 * Date: 17/3/8
 * Time: 8:25 AM
 */

require_once('inc/wodemaya.inc.php');
require_once(BX_DIRECTORY_PATH_INC . 'design.inc.php');
require_once(BX_DIRECTORY_PATH_INC . 'profiles.inc.php');
require_once(BX_DIRECTORY_PATH_INC . 'images.inc.php');
require_once(BX_DIRECTORY_PATH_INC . 'utils.inc.php');
require_once(BX_DIRECTORY_PATH_INC . 'checkout.inc.php');
require_once(BX_DIRECTORY_PATH_INC . 'functions.php');
//require_once( BX_DIRECTORY_PATH_INC . 'domain.php' );
require_once(BX_DIRECTORY_PATH_CLASSES . 'FlowTest.php');
require_once(BX_DIRECTORY_PATH_CLASSES . 'FormJoin.php');
//require_once (BX_DIRECTORY_PATH_INC.'brownse_statistics.php');
require_once(BX_DIRECTORY_PATH_CLASSES . 'wistia.php');
require_once(BX_DIRECTORY_PATH_CLASSES . 'FlowChat.class.php');
require_once(BX_DIRECTORY_PATH_INC . 'multi_domain_functions.inc.php');
require_once(BX_DIRECTORY_PATH_CLASSES . 'flow_count_down_ticker.class.php');

global $site;
global $parsed_oriuri;
global $dir;
global $tmpl;
global $wc_db;
global $basedir;
session_start();

require_once('inc/domain.php');

global $dbObj;
$dbObj->query("SET NAMES utf8");

class FunnelFlow
{
    private $id;
    private $visible; // Logedin, logedout
    private $step; // The sub (landing) page to show.
    private $userid;
    private $flowlogic; //  A FlowTest instance.
    private $subflowid;
    private $flowinfo; // An array that stores the information needed for rendering this flow.
    private $multiproducts;

    private $info;

    private $new_subscription_input_1;
    private $new_subscription_input_2;
    private $new_subscription_input_3;

    private $initprice_value;

    private $tempid;
    private $seed;
    private $exitflag;
    private $inittime;
    private $videoid;
    private $headline;
    private $button;
    private $lockdiv;
    private $parentflow;
    private $js;

    private function determineFlowId()
    {
        if ($_GET['lp']) {
            $_GET['templp'] = $this->id = $_GET['lp'];
        } else {
            $_GET['templp'] = $this->id = $_GET['id'];
            $_GET['lp'] = $this->id;
        }
    }

    private function setAffCookie()
    {
        if ((int)$_COOKIE['memberID'] && $_GET['aff']) {
            setcookie('oldUserBuyFromAff', $this->id . '---' . $_GET['aff'], time() + 3600);
            setcookie('aff', (int)$_GET['aff'], time() + 3600);
        }
    }

    private function getUserIdFromCookie()
    {
        if (isset($_COOKIE['memberID'])) {
            // Check that the password matches.
            if (member_auth(0, false, 1)) {
                $this->userid = (int)$_COOKIE['memberID'];
            }
        } else {
            $sth = db_res("SELECT LastIP FROM `Profiles_Last` WHERE ProfilesID = " . (int)$_COOKIE['NickName_Saved']);
            $arr = array();
            if ($arr = $sth->fetch() && $arr['LastIP'] == $_SERVER['REMOTE_ADDR']) {
                $this->userid = (int)$_COOKIE['NickName_Saved'];
            }
        }
    }

    /**
     * Check if this flow is visible according to user's status.
     */
    private function checkVisibility()
    {
        global $site;

        if ($this->step == 1) {
            if ((int)$_COOKIE['memberID'])//login in
            {
                if ($this->visible == 'loggedout') {
                    header('Location:' . $site['url']);
                    exit;
                }
            } else {
                if ($this->visible == 'loggedin') {
                    header('Location:' . $site['url'] . 'member.php');
                    exit;
                }
            }
        }
    }
    
    private function getRefererUrl()
    {
        $url = $_SERVER["HTTP_REFERER"];
        return $url;
    }

    private function chooseSubFlow()
    {
        $this->seed = (string)$_REQUEST['_s'];

        if (!$this->seed) {
            $this->seed = $this->flowlogic->createFlow();
            $this->flowinfo = $this->flowlogic->getRecordinfo($this->seed);
            $data = $_GET;
            if ($_COOKIE['hopofclickbank']) {
                $data['aff'] = $_COOKIE['hopofclickbank'];
            }
            $this->flowlogic->adtrack($data, $this->flowinfo['id']);
        } else {
            $this->flowinfo = $this->flowlogic->getRecordinfo($this->seed);
        }
        $this->inittime = (int)$_COOKIE['inittime_' . $this->flowlogic->_id];
        if (!$this->inittime) {
            $this->inittime = $this->flowinfo['dateline'];
            setcookie('inittime_' . $this->flowlogic->_id, $this->inittime, time() + 180 * 24 * 3600, '/');
        }
        $_REQUEST['tempseed'] = $this->seed;

        $_SESSION['abandon_str'] = $this->flowinfo['flowid'] . "---" . $this->flowinfo['subflowid'] . "---" . $this->step;

        // $tempid was used before, should have saved to avoid db query here.
        $this->tempid = $this->flowlogic->getTempID($this->seed);

        $this->subflowid = $this->flowinfo['subflowid'];

        $this->videoid = $this->flowinfo['subvideoid'];
        $this->headline = $this->flowinfo['subheadline'];
        $this->js = $this->flowlogic->addjs($this->subflowid);
        $this->button = $this->flowlogic->addpayBtn($this->subflowid, $this->step, $this->flowinfo['id']);

        $refererUrl = $this->getRefererUrl();
        if (preg_match("/facebook\.com/is", $refererUrl)) {
            $_SESSION['ref_fb']['url'] = $_SERVER["HTTP_REFERER"];
            $_SESSION['ref_fb']['subflowid'] = $this->subflowid;
            $_SESSION['ref_fb']['flowid'] = $this->flowinfo['id'];
        }
    }

    private function getMultiProducts()
    {
        $productsql = sprintf("select p.*,g.id as gid,g.name as gname,b.name as bname,g.feature,g.offer
            from flow_productsincheckout p,flow_bundlelist b,flow_bundlegroup g
            where p.bundleid=b.id and b.groupid=g.id and p.subflowid='%d'", $this->subflowid);

        $products = fill_assoc_array(db_res($productsql));

        $groups = array();
        foreach ($products as $pro) {
            $groups[$pro['gid'] . '-' . $pro['gname']][] = $pro;
        }

        ksort($groups);

        $multiproductscon = '<div class="contentbox">
                              <div class="contentboxtop">
                              </div>
                              <div class="contentboxmiddle">
                                <div class="contentbox_inner">
                                <div class="contentbox_title_bronze">
                                    %s
                                  </div>
                                  <div class="contentbox_text">       Features : %s
                                  </div>
                                   %s
                                   <div class="contentbox_text">
                                    <img src="./templates/base_9/images/subscrip/greencb_wt.png">         
                            
                                    <strong>Special offer:</strong>    %s
                                  </div>
                                  </div>
                              </div>
                              <div class="contentboxbottom">
                              </div>
                            </div> ';

        $prstrcon = '<div class="contentbox_sel selpadding">
                    <div class="contentbox_sellrt">
                      <input type="radio" name="data" class="products" value="%d">
                      <span class="levelhdr">
                        %s
                      </span>
                      <br>
                    </div>
                    <div class="clear">
                    </div>
                    <div class="contentbox_text">
                    </div>
                  </div>%s';

        $this->multiproducts = '';
        foreach ($groups as $ik => $grs) {
            $groupname = explode('-', $ik);
            $groupname = $groupname[1];
            $prostr = '';
            $or = '<div class="or_div"></div>';

            $moneystr = '<b>$%01.2f USD</b>/%d Days + <b>$%01.2f</b> USD one time fee';
            $monstr = '<b>$%01.2f USD</b> for %s';
            foreach ($grs as $pr) {
                $priceinpr = $pr['priceid'];
                $pricestr = db_arr(sprintf("select * FROM  `flow_subprice` where `pid`=%d", $priceinpr));
                $price = $pricestr['checkout'];
                $price_info = unserialize($pricestr['recurring_config']);
                if ($price_info['checkout']['rec'] == 1) {
                    $price_info['checkout']['fir'];
                    $money = sprintf($moneystr, $price_info['checkout']['bil'], $price_info['checkout']['fir'], $pricestr['checkout']);
                } else {
                    $money = sprintf($monstr, $pricestr['checkout'], $pr['bname']);
                }
                $prostr .= sprintf($prstrcon, $pr['id'], $money, $or);
                $or = '';
            }
            $this->multiproducts .= sprintf($multiproductscon,
                $groupname,
                $pr['feature'],
                $prostr,
                $pr['offer']);
        }
    }

    private function generateNewSubscriptionInputs()
    {
        ///new subscription
        $newSubscriptionSQL = "SELECT p.*,b.name AS bname
                            FROM flow_productsincheckout p, flow_bundlelist b,flow_subprice price
                            WHERE p.bundleid = b.id and price.pid=p.priceid
                            AND p.subflowid = '$this->subflowid' order by price.id asc";

        $new_p_list = fill_assoc_array(db_res($newSubscriptionSQL));
        $this->new_subscription_input_1 = "<input class=\"products\" type=\"radio\" value=\"{$new_p_list[0]['id']}\" name=\"data\" checked=\"checked\">";
        $this->new_subscription_input_2 = "<input class=\"products\" type=\"radio\" value=\"{$new_p_list[1]['id']}\" name=\"data\">";
        $this->new_subscription_input_3 = "<input class=\"products\" type=\"radio\" value=\"{$new_p_list[2]['id']}\" name=\"data\">";
        //end new subscription
    }

    private function calculateInitPrice()
    {
        $initprice = db_arr(sprintf("select * from flow_initprice where `subflowid`='%d'/* and `step`='%d'*/", $this->subflowid, $this->step));
        $this->initprice_value = '';
        if ($initprice['id']) {
            $subprices = fill_assoc_array(db_res(sprintf("select * from flow_subprice where `pid`='%d'", $initprice['priceid'])));

            $percs = array();
            $total = 0;
            foreach ($subprices as $sb) {
                //$sprices[$sb['id']]=$sb;
                $percs[$sb['id']] = $sb;
                $total += $sb['percent'];
            }
            $point = rand(1, $total);
            $sum = 0;
            $subpriceid = 0;
            foreach ($percs as $k => $c) {
                $sum += $c['percent'];
                if ($point <= $sum) {
                    $subpriceid = $k;
                    break;
                }
            }
            $this->initprice_value = $percs[$subpriceid]['checkout'];
        }
    }

    private function getInitStep()
    {
        $this->info = db_arr(sprintf("select * from payment_flow where id='%d'", $this->tempid));
        $initStep = 0;
        $this->lockdiv = array();
        if ($this->step == 1 && $this->info['previousID']) {
            $initStep = $this->info['previousID'];
            $exitflag = $this->info['sales_exitflag'];
        } elseif ($this->step > 1) {
            $this->tempid = intval($this->tempid);
            $this->step = intval($this->step);
            $inits = db_arr(sprintf("select * from flow_landpage where `flowid`='%d' and `step`='%d'", $this->tempid, $this->step - 1));
            //echo sprintf("select * from flow_landpage where `flowid`='%d' and `step`='%d'",$this->tempid,$step-1);
            $initStep = $inits['templateID'];
            $exitflag = $inits['exitflag'];

            $flowsteps = db_assoc_arr(
                sprintf("select sum(`unlocked`) as tot from flow_landpage where `flowid`='%d' and `step`<='%d'", $this->tempid, $this->step - 1)
            );

            $unlockedtime = $this->inittime + $flowsteps['tot'] * 24 * 3600;

            if (time() < $unlockedtime) {
                header(sprintf("location:flow.php?id=%s&_s=%s&_m=%d", $this->id, $this->seed, $this->step - 1));
                exit;
            }

            $flowsteparr = fill_assoc_array(
                db_res(
                    sprintf(
                        "select `unlocked`,`step` from flow_landpage where `flowid`='%d'", $this->tempid
                    )
                )
            );
            $lockicon = 0;
            $acclocked = 0;

            foreach ($flowsteparr as $fl) {
                $st = $fl['step'];
                $acclocked += $fl['unlocked'];

                $lockicon = $this->inittime + $acclocked * 24 * 3600;

                $this->lockdiv[$st] =

                $this->lockdiv[$st] = '<div class="locked"></div>';
                if (time() > $lockicon) {
                    $this->lockdiv[$st] = '';
                }
            }
            //echo $iniStep
        }
        return $initStep;
    }

    private function recordStepTrack()
    {
        $this->flowlogic->track('video', $this->flowinfo['id'], $this->flowinfo['flowid'], 'no', $this->step);
        $stepTrack = array();
        $stepTrack['flowid'] = $this->flowinfo['flowid'];
        $stepTrack['subflowid'] = $this->flowinfo['subflowid'];
        $stepTrack['userid'] = $this->flowinfo['userid'];
        $stepTrack['rid'] = $this->flowinfo['id'];
        $stepTrack['step'] = $this->step;
        $stepTrack['datetime'] = 'now()';

        new_query_insert('flow_steptrack', $stepTrack);
    }

    private $region;
    private $countryCode;
    private $countryCodeValue;
    private $city;

    private function getRegionInfo()
    {
        $geoArr = createCitySelect2();

        if ($geoArr['regionname']) {
            $this->region['name'] = $geoArr['regionname'];
        } else {
            $this->region = db_assoc_arr_wc(sprintf("select w.`name` from worldregion w where w.`ID`='%d' ", $geoArr['worldregionID']));
        }

        //$index_tmpl = str_replace('__city__', $Region['name'], $index_tmpl);
        $this->countryCode = $geoArr['countryCode'];
        $this->countryCodeValue = $geoArr['countryCodeValue'];
        $this->city = $geoArr['city'];
    }

    private $media_video_config;
    private $headline_config;

    private function getMediaAndHeadlineConfig()
    {
        $mediaArray = db_arr(sprintf("select * from flow_subvideo where `id`='%d'", $this->videoid));
        $this->media_video_config = unserialize($mediaArray['config']);
        $headline_info = db_arr(sprintf("select * from flow_subheadline where `id`='%d'", $this->headline));
        $this->headline_config = unserialize($headline_info['config']);

        $aff = (int)$_COOKIE['aff'];
        if ($aff) {
            $affHeadLine = db_arr(sprintf("select * from flow_subheadlineforaff where subid='%d' and `aff`='%d' and `checked`='1'", $this->headline, $aff));

            if ($affHeadLine['linktoheadid']) {
                $subHeadLines = fill_assoc_array(db_res(sprintf("select * from flow_subheadline where `pid`='%d'", $affHeadLine['linktoheadid'])));

                $percents = array();
                $total = 0;
                foreach ($subHeadLines as $sb) {
                    $percents[$sb['id']] = $sb['percent'];
                    $total += $sb['percent'];
                }
                $point = rand(1, $total);
                $sum = 0;
                $subHeadLineId = 0;
                foreach ($percents as $k => $c) {
                    $sum += $c;
                    if ($point <= $sum) {
                        $subHeadLineId = $k;
                        break;
                    }
                }
                if ($subHeadLineId) {
                    $headline_info = db_arr(sprintf("select * from flow_subheadline where `id`='%d'", $subHeadLineId));
                    $this->headline_config = unserialize($headline_info['config']);
                }
            }
        }
    }

    /**
     * @param string $index_tmpl The original html template content.
     * @return string The html text with webinar related tags replaced.
     */
    private function fillWebinarInfo($index_tmpl)
    {
        $cityId = $_REQUEST['City'];

        $time_zone = db_assoc_arr_wc(sprintf("SELECT `timezone` FROM `worldcities` WHERE `ID` =%d", $cityId));

        if ($time_zone['timezone'] == 'unknown' || $time_zone['timezone'] == '()') {
            $offset = 0;
        } else {
            try {
                $offset = get_timezone_offset($time_zone['timezone']);
            } catch (Exception $y) {
                $offset = 0;
            }
        }

        if ($_REQUEST['_wid']) {
            $webinar = db_arr(sprintf("select * from webinar where `id`='%d' ", (int)$_REQUEST['_wid']));
            $webinarSecurityType = db_arr(sprintf("select * from webinar_sectype where `wid`='%d'", $_REQUEST['_wid']));

            $nextTime = $webinar['starttime'];
            $stealthOptions = '';
            if ($webinarSecurityType['type'] == 'no') {
                $specifieds = db_arr(sprintf("select * from webinar_sec where wid='%d' and `dateline`>'%d' order by `dateline` asc ",
                    $_REQUEST['_wid'], time()));

                $nextDate = $specifieds['dateline'];
            } elseif ($webinarSecurityType['type'] == 'day') {
                $nextDate = time();
                if ($nextDate > strtotime(date('Y-m-d') . ' ' . date('H:i:s', $nextTime))) {
                    $currentweb = db_arr(sprintf("
                        select *
                        from webinar_livequeue
                        where `wid`='%d' and endflag='0'", $_REQUEST['_wid']));
                    if ($currentweb['starttime'] && $currentweb['starttime'] >= time() - 6 * 3600) {
                        $nextDate = $nextDate;
                    } else {
                        $nextDate = $nextDate + 24 * 3600;
                    }
                }
            } elseif ($webinarSecurityType['type'] == 'week') {
                $dayOfCurrWeek = date('w', time());
                $dayofw = date('w', strtotime($webinarSecurityType['startdate']));
                if ($dayOfCurrWeek > $dayofw) {
                    $nextDate = time() + ($dayOfCurrWeek + 7 - $dayofw) * 24 * 3600;
                } else {
                    $nextDate = time() + ($dayofw - $dayOfCurrWeek) * 24 * 3600;
                }
            } elseif ($webinarSecurityType['type'] == 'month') {
                $dayofcm = date('d', time());
                $dayofm = date('d', strtotime($webinarSecurityType['startdate']));
                if ($dayofcm > $dayofm) {
                    $nextDate = time() + ($dayofcm + 30 - $dayofm) * 24 * 3600;
                } else {
                    $nextDate = time() + ($dayofm - $dayofcm) * 24 * 3600;
                }
            }
            if (!$nextDate) {
                $nextDate = time();
            }

            if ($webinar['stealthseminar']) {
                $slformat = '<div class="box1 col-sm-4 col-xs-12">
        <input type="radio" %s  class="css-checkbox" id="radio%d" name="sequence" value="%d"><label class="css-label radGroup2" for="radio%d">%s, %s %d at %s %s (Pacific) <img src="http://www.tailopez.com/images/efd2/files/GrandTheory/images/time.jpg">
            	<span>(Only <strike>100</strike> 25 seats left for this event time.)</span></label>


        </div>';
                //$slformat = '<p> <input name="sequence" type="radio" value="%d" > %s, %s %d at %s %s (Pacific)</p>';
                //foreach($specifieds as $sk=>$sp)
                for ($si = 0; $si < 3; $si++) {
                    $checked = '';

                    switch ($si) {
                        case 0:
                            $dt = strtotime(date('Y-m-d H:00', time() + 3600));
                            $checked = 'checked';
                            break;
                        case 1:
                            $dt = strtotime(date('Y-m-d 11:00', time() + 3600 * 24));
                            break;
                        case 2:
                            $dt = strtotime(date('Y-m-d 19:00', time() + 2 * 3600 * 24));
                            break;
                    }

                    $stealthOptions .= sprintf($slformat
                        , $checked
                        , $si
                        , $dt
                        , $si
                        , date('l', $dt)
                        , date('F', $dt)
                        , date('d', $dt)
                        , date('h:i', $dt)
                        , date('a', $dt)
                    );
                }

                $index_tmpl = str_replace('__stealthoptions__', $stealthOptions, $index_tmpl);
                $stealthseminar = array();
                $stealthseminar['seed'] = $this->seed;
                $stealthseminar['wid'] = $webinar['id'];
                $stealthseminar['sent'] = '0';
                $stealthseminar['sent_dateline'] = '0';
                $stealthseminar['wdatetime'] = date('Y-m-d H:i', $nextDate);
                $stealthseminar['eventid'] = $webinar['stealthseminar'];
                new_query_insert('stealthseminar_log', $stealthseminar);
            }

            $newyear = date('Y', $nextDate);
            $newmonth = date('m', $nextDate);
            $newdate = date('d', $nextDate);
            $newhour = date('H', $nextTime);
            $newmin = date('i', $nextTime);
            $week = date('D', $nextDate);

            $newoffyear = date('Y', $nextDate - $offset);
            $newoffmonth = date('m', $nextDate - $offset);
            $newoffdate = date('d', $nextDate - $offset);
            $newoffhour = date('H', $nextTime - $offset);
            $newoffmin = date('i', $nextTime - $offset);

            $index_tmpl = str_replace('{nextmonth}', $newmonth, $index_tmpl);
            $index_tmpl = str_replace('{nextyear}', $newyear, $index_tmpl);
            $index_tmpl = str_replace('{nexthour}', $newhour, $index_tmpl);
            $index_tmpl = str_replace('{nextmin}', $newmin, $index_tmpl);
            $index_tmpl = str_replace('{nextday}', $newdate, $index_tmpl);
            $index_tmpl = str_replace('{week}', $week, $index_tmpl);

            $index_tmpl = str_replace('{nextoffmonth}', $newoffmonth, $index_tmpl);
            $index_tmpl = str_replace('{nextoffyear}', $newoffyear, $index_tmpl);
            $index_tmpl = str_replace('{nextoffhour}', $newoffhour, $index_tmpl);
            $index_tmpl = str_replace('{nextoffday}', $newoffdate, $index_tmpl);
            $index_tmpl = str_replace('{nextoffmin}', $newoffmin, $index_tmpl);

            //TAIL-2585 - webinar title tokens
            $index_tmpl = str_replace('{webinar-title}', $webinar['webinar_title'], $index_tmpl);

            //{countdown-timer-time-02-04-2016-12-00-00}
            preg_match_all('/{countdown-timer-time-(.*?)}/is', $index_tmpl, $countdowns2);
            $timers2 = $countdowns2[1];

            $tokens2 = $countdowns2[0];
            //month-day-year-hour-am or pm
            foreach ($timers2 as $tk => $tm) {
                list($tmonth, $tday, $tyear, $thour, $tmin, $tam) = explode('-', $tm);
                if ($tam == 'pm' && $thour < 12) {
                    $thour = $thour + 12;
                }
                $jstime = $tyear . '-' . str_pad($tmonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($tday, 2, '0', STR_PAD_LEFT);
                $jstime .= ' ' . str_pad($thour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($tmin, 2, '0', STR_PAD_LEFT) . ':00';
                $index_tmpl = str_replace($tokens2[$tk], strtotime($jstime), $index_tmpl);
            }

            preg_match_all('/{countdown-timer-(.*?)}/is', $index_tmpl, $countdowns);
            $timers = $countdowns[1];

            $tokens = $countdowns[0];
            //month-day-year-hour-am or pm
            foreach ($timers as $tk => $tm) {
                list($tmonth, $tday, $tyear, $thour, $tmin, $tam) = explode('-', $tm);
                if ($tam == 'pm' && $thour < 12) {
                    $thour = $thour + 12;
                }
                $jstime = $tyear . '-' . str_pad($tmonth, 2, '0', STR_PAD_LEFT) . '-' . str_pad($tday, 2, '0', STR_PAD_LEFT);
                $jstime .= ' ' . str_pad($thour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($tmin, 2, '0', STR_PAD_LEFT) . ':00';
                $index_tmpl = str_replace($tokens[$tk], $jstime, $index_tmpl);
            }

            $append = '';
            if ($_GET['source']) {
                $append = '&source=' . $_GET['source'];
            }
            if ($_GET['aff']) {
                $append .= '&aff=' . $_GET['aff'];
            }

            $index_tmpl = str_replace('__wcdate__', date('Y-m-d', $nextDate) . $append, $index_tmpl);

        }
        return $index_tmpl;
    }

    private $wistiahash;

    private function getVideoPlayCode() {
        if ($this->media_video_config['media'][0][$this->step - 1])
            $mediainfo = db_arr(sprintf("select * from media_library where id='%d'", $this->media_video_config['media'][0][$this->step - 1]));

        $this->wistiahash = $mediainfo['wistiahash'];
        $source_str = '';
        if ($mediainfo['encoded'] == 1) {
            $sources = fill_assoc_array(db_res(sprintf("select * from encoded_library where `mid`='%d'", $this->media_video_config['media'][0][$this->step - 1])));
            $objstr = $comm = '';
            //$source_str='image: "/images/play_t_image.png",';
            if (count($sources) >= 1) {
                foreach ($sources as $source) {
                    $objstr .= $comm . "{file:'" . $source['url'] . "',
                              type:'" . $source['type'] . "'}";
                    $comm = ',';
                }
                $source_str .= 'sources: [' . $objstr . ']';
            } else {
                $source_str .= "file:'" . $mediainfo['url'] . "'";
            }

        } else {
            //$source_str='image: "/images/play_t_image.png",';
            $source_str .= "file:'" . $mediainfo['url'] . "'";
        }
        $autostart = $this->media_video_config['autostart'][0][$this->step - 1] ? 'true' : 'false';
        $controls = $this->media_video_config['controls'][0][$this->step - 1] ? 'true' : 'false';
        $apause = $this->media_video_config['apause'][0][$this->step - 1] ? '' : 'this.play();';
        if ( ( $this->media_video_config['player'][0][$this->step - 1] == 'cdn' || $mediainfo['uploadboth'] == 'cdn') ){
            $media = '
	<script src="/inc/js/jquery-1.4.2.min.js"></script>
	<script src="/inc/js/jwplayer/jwplayer.js"></script>
			<script src="/inc/js/jwplayer/jwrecord.js"></script>
<script>jwplayer.key="gujIIe4O5f5jJkX3E3Dv1lXBhk/dmH11EU1Q8uCVYis="</script>';
            $media .= "<div id='my-video'></div>";
            $media .= "<script type='text/javascript'>
  jwplayer('my-video').setup({
    $source_str,
    width: '" . $this->media_video_config['width'][0][$this->step - 1] . "',
    height: '" . $this->media_video_config['height'][0][$this->step - 1] . "',
    autostart: '$autostart',
    controls: '$controls',
    stretching: 'fill',
    ga: {},
    events: {
         onPause: function(event) {
            $apause
            jwRecordPause(event); 
                },
             onPlay:jwRecordStart,
                onIdle:jwRecordIdle,
				onTime:jwRecordOn,
				onComplete: jwRecordDone  
    }
     });
     </script>";
        } else {
            $wistia = new wistia();
            $controls = $this->media_video_config['controls'][0][$this->step - 1] ? '0' : '1';
            $pause = $this->media_video_config['apause'][0][$this->step - 1] ? '0' : '1';
            $params = array(
                'autostart' => (int)$this->media_video_config['autostart'][0][$this->step - 1]
            , 'controls' => (int)$controls
            , 'hidePause' => (int)$pause
            , 'hidePlayerBar' => (int)$controls
            );
            $params['width'] = $this->media_video_config['width'][0][$this->step - 1];
            $params['height'] = $this->media_video_config['height'][0][$this->step - 1];

            //$lesson_info['videoUrl'] = $media['url'];
            $params['hashed_id'] = $mediainfo['wistiahash'];
            //print_r($params);
            $media = '';
            $media .= $wistia->createPlayerHTML($params);
        }
        if ($this->media_video_config['media_type'][0][$this->step - 1] == 'url') {
            if ($this->media_video_config['autostart'][0][$this->step - 1] == 1) {
                $autoplaystr = '&autoplay=1&autoPlay=true';
            }
            if (!$this->media_video_config['controls'][0][$this->step - 1]) $autoplaystr .= '&controls=0';
            $media = '<iframe name="youtubevideo_iframe" id="youtubevideo_iframe" width="'
                . $this->media_video_config['width'][0][$this->step - 1] . '" height="'
                . $this->media_video_config['height'][0][$this->step - 1] . '" src="'
                . $this->media_video_config['media_url'][0][$this->step - 1]
                . '?a=1' . $autoplaystr . '&fullscreenButton=false&playButton=true&playbar=false&smallPlayButton=false&showinfo=0&version=v1&enablejsapi=1" frameborder="0" allowfullscreen></iframe>';

            global $site;
            if ($site['multi_domain']['extend'] == 'tailopez'
                && (($this->flowinfo['flowid'] == 314 && $this->flowinfo['subflowid'] == 1746)
                    || ($this->flowinfo['flowid'] == 326 && $this->flowinfo['subflowid'] == 1791)
                    || ($this->flowinfo['flowid'] == 319))
            ) {
                $user_agent = (!isset($_SERVER['HTTP_USER_AGENT'])) ? FALSE : $_SERVER['HTTP_USER_AGENT'];
                $iphone = strstr(strtolower($user_agent), 'mobile');
                $ipad = strstr(strtolower($user_agent), 'ipad');
                if ($ipad || $iphone) {
                    $media = '<script type="text/javascript" src="/inc/js/jsvideo230415.js"></script>
                <div id="iph"></div>
                ';

                    $media .= <<<M

<style>
#video_panel{background:none!important;padding:0!important;}
                #video_panel p{padding-top:0px!important;}

                #video_panel .video {width:90%;
                padding:0!important;
  background: none!important;}
  .button_t{margin-top: -20px;}
 #video_panel p {padding: 0px;}
</style>
                <script  type="text/javascript">
//<![CDATA[
                $(document).ready(function(){
                });


                var playing = false;
                var isplay=false;
                $('#iph').replaceWith('<div class="smallPl" style="position: absolute;left: 50%; margin-left:-58px;top: 50%;margin-top:-50px;z-index:999999;" id="iv"><img class="spbut" src="http://s.easy-bits.com/images/layout/strobe.play.png" /></div>');
                var player = new video_jsv();
                $('.video')[0].appendChild(player);
                player.setAttribute('preload', 1);
                //player.style.width = '100%';
                //player.style.height = '180px';
                player.setAttribute('src', '/media/variation-df19d-6fc59-fda1c-35a47.jsv');
                player.setAttribute('data-audio', '/media/variation-df19d-6fc59-fda1c-35a47.mp3');

                if(playing){
					$('.spbut').hide();
					//player.play();
					player.addEventListener('canplay', function(){
						player.play();
					});

					$('.video').click(function(){
					    if(!isplay){
					    player.play();
						$('.spbut').hide();
						isplay=true;
					    }else
					    {
					    player.pause();
						$('.spbut').show();
						isplay=false;
					    }

					});
				}else{


					$('.video').click(function(){
					    if(!isplay){
					    player.play();
						$('.spbut').hide();
						isplay=true;
					    }else
					    {
					    player.pause();
						$('.spbut').show();
						isplay=false;
					    }

					});
				}

				player.addEventListener('pause', function(){
						$('.spbut').show();
						isplay=false;
					});
                //]]>
                </script>
M;

                }
            }
            //$media='<iframe width="'.$this->media_video_config['width'][0][$this->step-1].'" height="'.$this->media_video_config['height'][0][$this->step-1].'" src="'.$this->media_video_config['media_url'][0][$this->step-1].'?a=1'.$autoplaystr.'&fullscreenButton=false&playButton=true&playbar=false&smallPlayButton=false&version=v1" frameborder="0" allowfullscreen></iframe>';
        }
        return $media;
    }

    private function render($initStep)
    {
        global $site;

        $paymenttmpl = db_arr(sprintf("select * from payment_templates where id='%d'", $initStep));
        template_view_count($paymenttmpl['id']);
        $form = new FormJoin();

        // strip
        $index_tmpl = stripslashes(stripslashes($paymenttmpl['html']));
        $index_tmpl = str_replace('Â', '', $index_tmpl);
        $index_tmpl = str_replace('â€™', "'", $index_tmpl);
        $index_tmpl = str_replace('â€œ', '', $index_tmpl);
        $index_tmpl = str_replace('â€˜', ' ', $index_tmpl);
        $index_tmpl = str_replace('â€', '', $index_tmpl);
        $index_tmpl = str_replace('�', ' ', $index_tmpl);
        $hide = 0;

        if (strpos($index_tmpl, '__hidden_fields__')) {
            $hide = 1;
        }

        if (preg_match("/__page_topmenu__/i", $index_tmpl)) {
            $PageHeaderHtml = new PageHeaderHtml();
            $topHtml = $PageHeaderHtml->getTopHtml();
            $index_tmpl = str_replace('__page_topmenu__', $topHtml, $index_tmpl);
        }

        if (preg_match("/__INDEX_FUNCTION_PARSE__/i", $index_tmpl)) {
            $index_tmpl = str_replace('__INDEX_FUNCTION_PARSE__', '', $index_tmpl);
            $link[] = 'flow.php?' . substr($_SERVER['QUERY_STRING'], 0, strlen($_SERVER['QUERY_STRING']) - 1) . "1";
            $link[] = 'flow.php?' . substr($_SERVER['QUERY_STRING'], 0, strlen($_SERVER['QUERY_STRING']) - 1) . "2";
            $link[] = 'flow.php?' . substr($_SERVER['QUERY_STRING'], 0, strlen($_SERVER['QUERY_STRING']) - 1) . "3";
            $link[] = 'flow.php?' . substr($_SERVER['QUERY_STRING'], 0, strlen($_SERVER['QUERY_STRING']) - 1) . "4";
            $index_tmpl = str_replace('__STEP1_LINK__', $link[0], $index_tmpl);
            $index_tmpl = str_replace('__STEP2_LINK__', $link[1], $index_tmpl);
            $index_tmpl = str_replace('__STEP3_LINK__', $link[2], $index_tmpl);
            $index_tmpl = str_replace('__STEP4_LINK__', $link[3], $index_tmpl);
            $formhtml = $form->defaultFormHtml($this->flowinfo['joinflow'], $this->step, $paymenttmpl['formrefresh'], 0, 0, $this->flowinfo['id'], $hide);
        } else {
            $hidejs = $_REQUEST['js'];
            $formhtml = $form->formHtml($this->flowinfo['joinflow'], $this->step, $paymenttmpl['formrefresh'], 0, $hidejs, $this->flowinfo['id'], $hide);
            $index_tmpl = str_replace('__lp__', $_REQUEST['lp'], $index_tmpl);
            $index_tmpl = str_replace('__templlp__', $_REQUEST['templp'], $index_tmpl);
            $index_tmpl = str_replace('__seed__', $_REQUEST['tempseed'], $index_tmpl);
        }

        $template_footer = footer_funnel($this->info['footer_show']);
        $template_footer = str_ireplace('{current year}', date('Y'), $template_footer);
        $index_tmpl = str_replace('__rick_footer__', $template_footer, $index_tmpl);
        $headline_txt = ($this->headline_config['headline'][0][$this->step - 1]) . '<br />' . ($this->headline_config['subheadline'][0][$this->step - 1]);
        if ($_SERVER['HTTPS'] == 'on') {
            //$index_tmpl = str_replace('__site_url__',$site['url_https'], $index_tmpl);
            $index_tmpl = str_replace('__site_url__', $site['multi_domain']['url'], $index_tmpl);
        } else {
            $index_tmpl = str_replace('__site_url__', $site['multi_domain']['url'], $index_tmpl);
        }

        $media = $this->getVideoPlayCode();

        $title = "\$initStep:". $initStep;

        $index_tmpl = str_replace('__wistiahash__', $this->wistiahash, $index_tmpl);
        $index_tmpl = str_replace('__title__', $title, $index_tmpl);
        //$index_tmpl = str_replace('__title__', $paymenttmpl['title'], $index_tmpl);
        $index_tmpl = str_replace('__multi_products__', $this->multiproducts, $index_tmpl);
        $index_tmpl = str_replace('__new_subscription_input_1__', $this->new_subscription_input_1, $index_tmpl);
        $index_tmpl = str_replace('__new_subscription_input_2__', $this->new_subscription_input_2, $index_tmpl);
        $index_tmpl = str_replace('__new_subscription_input_3__', $this->new_subscription_input_3, $index_tmpl);
        $index_tmpl = str_replace('__meta_description__', $paymenttmpl['desc'], $index_tmpl);
        $index_tmpl = str_replace('__keyword__', $paymenttmpl['keyword'], $index_tmpl);

        $index_tmpl = str_replace('__city__', $this->region['name'], $index_tmpl);
        $index_tmpl = str_replace('__region_name__', $this->region['name'], $index_tmpl);
        $index_tmpl = str_replace('__city_name__', $this->city, $index_tmpl);
        $index_tmpl = str_replace('__flowid__', $this->id, $index_tmpl);
        if ($this->button['place'] == 'bottom' || $this->button['place'] == 'both') {
            $index_tmpl = str_replace('__BuyNowButton__', $this->button['btn'], $index_tmpl);
            $index_tmpl = str_replace('__Button__', $this->button['btn'], $index_tmpl);
        } else {
            $index_tmpl = str_replace('__BuyNowButton__', '', $index_tmpl);
            $index_tmpl = str_replace('__Button__', '', $index_tmpl);
        }
        if ($this->button['place'] == 'top' || $this->button['place'] == 'both') {
            $index_tmpl = str_replace('__TopButton__', $this->button['btn'], $index_tmpl);
        } else {
            $index_tmpl = str_replace('__TopButton__', '', $index_tmpl);
        }

        $index_tmpl = str_replace('__Domain__', ucfirst($site['multi_domain']['name']), $index_tmpl);

        $index_tmpl = str_replace('__media_selected__', $media, $index_tmpl);
        $index_tmpl = str_replace('__Headline__', $this->headline_config['headline'][0][$this->step - 1], $index_tmpl);
        $index_tmpl = str_replace('__Subheadline__', $this->headline_config['subheadline'][0][$this->step - 1], $index_tmpl);
        $index_tmpl = str_replace('__site_url__', $site['multi_domain']['url'], $index_tmpl);
        $index_tmpl = str_replace('__JsJoin__', '', $index_tmpl);
        $index_tmpl = str_replace('__Joinfrom__', $formhtml, $index_tmpl);
        $index_tmpl = str_replace('__joinfrom__', $formhtml, $index_tmpl);
        $index_tmpl = str_replace('__hidden_fields__', $formhtml, $index_tmpl);
        $index_tmpl = str_replace('__logo__', $site['multi_domain']['logo'], $index_tmpl);
        $index_tmpl = str_replace('__initprice__', $this->initprice_value, $index_tmpl);
        $index_tmpl = str_replace('__formid__', $this->flowinfo['joinflow'], $index_tmpl);
        $index_tmpl = str_replace('__flowrecordid__', $this->flowinfo['id'], $index_tmpl);
        $index_tmpl = str_replace('__seed__', $this->seed, $index_tmpl);
        $user_info = get_user_info($this->userid);
        $index_tmpl = str_replace("__FirstName__", $user_info['FirstName'], $index_tmpl);
        $index_tmpl = str_replace("__LastName__", $user_info['LastName'], $index_tmpl);
        $index_tmpl = str_replace("__Phone1__", $user_info['CellPhone'], $index_tmpl);
        $index_tmpl = str_replace("__Email__", $user_info['Email'], $index_tmpl);
        $index_tmpl = str_replace("__zip__", $user_info['zip'], $index_tmpl);
        $index_tmpl = str_replace("__StreetAddress1__", $user_info['mailingAddress'], $index_tmpl);
        $index_tmpl = str_replace("__visittime__", $this->inittime, $index_tmpl);
        $index_tmpl = str_replace("__currenttime__", time(), $index_tmpl);
        $x_currency = GetProfileCurrency((int)$_COOKIE['memberID']);
        $x_currency_symbol = getCurrencySymbol($x_currency);
        $index_tmpl = str_replace('__x_currency_symbol__', $x_currency_symbol, $index_tmpl);
        $index_tmpl = str_replace('{current year}', date('Y'), $index_tmpl);
        foreach ($this->lockdiv as $k => $hv) {
            $index_tmpl = str_replace("__locked" . $k . "__", $hv, $index_tmpl);
        }

        if (preg_match('/__fb_likes__/', $index_tmpl)) {
            $n = db_arr(sprintf("select value from cron_query_result where `keys`='fb_likes' and domain=%d", $site['multi_domain']['id']));
            $index_tmpl = str_replace("__fb_likes__", number_format($n['value']), $index_tmpl);
        }
        if (preg_match('/__tw_likes__/', $index_tmpl)) {
            $n = db_arr(sprintf("select value from cron_query_result where `keys`='twitter_followers' and domain=%d", $site['multi_domain']['id']));
            $index_tmpl = str_replace("__tw_likes__", number_format($n['value']), $index_tmpl);
        }
        if (preg_match('/__instagram_likes__/', $index_tmpl)) {
            $n = db_arr(sprintf("select value from cron_query_result where `keys`='instagram_followers' and domain=%d", $site['multi_domain']['id']));
            $index_tmpl = str_replace("__instagram_likes__", number_format($n['value']), $index_tmpl);
        }
        if (preg_match('/__youtube_likes__/', $index_tmpl)) {
            $n = db_arr(sprintf("select value from cron_query_result where `keys`='youtube_followers' and domain=%d", $site['multi_domain']['id']));
            $index_tmpl = str_replace("__youtube_likes__", number_format($n['value']), $index_tmpl);
        }

        if (preg_match('/__67steps_count_span__/', $index_tmpl)) {
            $paid67steps = str_split(strval(getParam('67steps_count')));
            $paid67steps_str = '';
            foreach ($paid67steps as $paid67steps_v) {
                $paid67steps_str .= '<span>' . $paid67steps_v . '</span>';
            }
            $index_tmpl = str_replace("__67steps_count_span__", $paid67steps_str, $index_tmpl);
        }
        if (preg_match('/__accelerator_count_span__/', $index_tmpl)) {
            $paidaccelerators = str_split(strval(getParam('accelerator_count')));
            $paidaccelerators_str = '';
            foreach ($paidaccelerators as $paidaccelerators_v) {
                $paidaccelerators_str .= '<span>' . $paidaccelerators_v . '</span>';
            }
            $index_tmpl = str_replace("__accelerator_count_span__", $paidaccelerators_str, $index_tmpl);
        }

        if (preg_match('/<\/body>/', $index_tmpl)) {
            $code = flow_count_sown_ticker::get_count_sown_ticker($this->flowinfo['id'], $this->flowinfo['flowid'], $this->flowinfo['subflowid']);
            $index_tmpl = str_replace("</body>", $code . '</body>', $index_tmpl);
            $index_tmpl = str_replace('__count_down_ticker__', '', $index_tmpl);
        }

        $stealthseminar = db_assoc_arr(sprintf("select * from stealthseminar_log where `seed`='%s' and `sent`=1", $this->seed));

        //$index_tmpl = str_replace ( "__stealthseminarevent__", $stealthseminar['eventid'], $index_tmpl);
        if ($_REQUEST['_m'] == 2 && $stealthseminar['eventid']) {
            if (strpos($index_tmpl, '__stealthseminarevent__')) {
                $index_tmpl = str_replace("__stealthseminarevent__", $stealthseminar['eventid'], $index_tmpl);
            } else {
                header(sprintf("location:http://www.onlinemeetingnow.com/seminar/?id=%s&name=%s&email=%s", $stealthseminar['eventid'], $user_info['FirstName'], $user_info['Email']));
                exit;
            }
        }

        $index_tmpl = str_replace('__site_id__', $site['multi_domain']['id'], $index_tmpl);
        $index_tmpl = str_replace("__flow_lp__", $this->id, $index_tmpl);
        $index_tmpl = str_replace("__flow_seed__", $this->seed, $index_tmpl);
        $index_tmpl = str_replace("__privacy_terms_of_use__", _t('privacy_terms_of_use'), $index_tmpl);

        $index_tmpl = $this->fillWebinarInfo($index_tmpl);

        if ($_REQUEST['wcdate'])
            $index_tmpl = str_replace('__wcdate__', $_REQUEST['wcdate'], $index_tmpl);
        $index_tmpl = str_replace('__wcdate__', date('Y-m-d', $_COOKIE['nextwebd']), $index_tmpl);
        // $index_tmpl = str_replace('__redirecturl__', $site[url_https].'flow.php?id='.$id.'&_s='.$seed.'&_m='.($this->step+1), $index_tmpl);
        $index_tmpl = str_replace('__redirecturl__', $site['url'] . 'flow.php?id=' . $this->id . '&_s=' . $this->seed . '&_m=' . ($this->step + 1), $index_tmpl);
        $_SESSION['redirecturl'] = $site['url'] . 'flow.php?id=' . $this->id . '&_s=' . $this->seed . '&_m=' . ($this->step + 1);

        $index_tmpl = $this->flowlogic->thumbails($index_tmpl, $this->flowinfo['thumbnail']);

        if ($this->countryCodeValue == 1) {
            $phone_str = sprintf('<input name="areaCode" id="areaCode" type="text" placeholder="Area Code"  class="input join-input"  value=""> - <input name="MobileNumber_1" id="MobileNumber_1" type="text" placeholder="Mobile"  class="input join-input"  value=""> - <input name="MobileNumber_2" id="MobileNumber_2" type="text" placeholder="Phone"  class="input join-input"  value="">');
            $index_tmpl = str_replace('__countryphone__', $phone_str, $index_tmpl);
        } else {
            $index_tmpl = str_replace('__countryphone__', '', $index_tmpl);
        }

        require_once(BX_DIRECTORY_PATH_CLASSES . 'pixel.php');
        $tempurl = trim($_SERVER['SCRIPT_NAME'], '/');
        $pl = new pixel($tempurl, 'header', $site['multi_domain']['id'], $this->seed, $this->parentflow['pixelTagID']);
        $headerstr = $pl->getpixel();

        $index_tmpl = str_replace('</head>', $headerstr . '</head>', $index_tmpl);

        $pl = new pixel($tempurl, 'body', $site['multi_domain']['id'], $this->seed, $this->parentflow['pixelTagID']);
        $bodystr = $pl->getpixel();

        $index_tmpl = str_replace('</body>', $bodystr . '</body>', $index_tmpl);
        $index_tmpl = str_replace('__recordID__', $this->flowinfo['id'], $index_tmpl);

        if (strpos($index_tmpl, '__localTranID__')) {
            $appendhop = '';
            if ($_COOKIE['hopofclickbank']) {
                $hopofclickbank = $_COOKIE['hopofclickbank'];
                $appendhop = '&hop=' . $hopofclickbank;
            }
            $price = 0;
            $collectDataArr['checkout_action'] = 'product';
            $collectDataArr['amount'] = process_pass_data($price);
            $collectDataArr['data'] = process_pass_data($this->flowinfo['id'] . '-' . $price . '-30');
            $collectDataArr['from'] = $this->flowinfo['id'];
            $collectDataArr['description'] = '';//returnDescByAction( $collectDataArr['checkout_action'], $productinfo['name'], true );

            $localTranID = initiateTransaction($collectDataArr, $this->userid, '0', 'primary');
            $index_tmpl = str_replace('__localTranID__', $localTranID . $appendhop, $index_tmpl);
        }

        echo $index_tmpl;
        echo $this->button['js'];

        if ($this->step == 1) {
            if ($this->flowinfo['salefailure']) {
                if (strpos($this->js, '__abandonjs__') !== false && $this->exitflag) {
                    $failjs = $this->flowlogic->injectjs($this->flowinfo['salefailure']);
                    if ($_SERVER['HTTPS'] == 'on') {
                        //$index_tmpl = str_replace('__site_url__',$site['url_https'], $index_tmpl);
                        $failjs = str_replace('__site_url__', $site['multi_domain']['url'], $failjs);
                    } else {
                        $failjs = str_replace('__site_url__', $site['multi_domain']['url'], $failjs);
                    }
                    $this->js = str_replace('__abandonjs__', $failjs, $this->js);

                } else {
                    $failjs = $this->flowlogic->abandonjs($this->flowinfo['salefailure']);
                    if ($_SERVER['HTTPS'] == 'on') {
                        //$index_tmpl = str_replace('__site_url__',$site['url_https'], $index_tmpl);
                        $failjs = str_replace('__site_url__', $site['multi_domain']['url'], $failjs);
                    } else {
                        $failjs = str_replace('__site_url__', $site['multi_domain']['url'], $failjs);
                    }
                    echo $failjs;
                }

            }
        }
        if ($this->exitflag) {
            $this->js = str_replace('__abandonjs__', '', $this->js);
            echo $this->js;
        }
    }

    private function redirectToOtherPages()
    {
        global $site;

        $tempid = $this->flowinfo['tempid'];
        $typeinfo = db_arr(sprintf("select `flow_type` from payment_flow where `id`='%d'", $tempid));
        if ($typeinfo['flow_type'] == 'join') {
            $info = db_arr(sprintf("select * from flow_records where `id`='%d'", $this->flowinfo['id']));
            $p_arr = db_arr("select ID,Email,Password,Sex,City,DateOfBirth,Country,Region,sexuality,registerurl from Profiles where ID='"
                . $this->flowinfo['userid'] . "'");

            $p_arr['Password'] = enmcrypt($p_arr[Password]);
            /*setcookie("memberID", $_COOKIE['memberID'], time() - 3600, '/');
            setcookie("memberPassword", $_COOKIE['memberPassword'], time() - 3600, '/');
            setcookie("memberID", $p_arr['ID'], time() + 10800, '/');
            setcookie("memberPassword", $p_arr['Password'], time() + 10800, '/');
            setcookie("NickName_Saved", $p_arr['ID'], time() + 3600, '/');
            */
            require_once(BX_DIRECTORY_PATH_CLASSES . 'profiles.class.php');
            $p = new profiles($p_arr['ID']);
            $p->login($p_arr['ID'], $p_arr['Password'], 1);

            if ($info['sublasturl']) {
                $lasturl = db_arr(sprintf("select * from flow_lasturl where id='%d'", $info['sublasturl']));
                if ($lasturl['url']) {
                    header("location:" . $site['url'] . $lasturl['url']);
                    exit(0);
                }
            } else {
                header("location:" . $site['url'] . 'member.php');
                exit(0);
            }
        } else {
            header("location:" . $site['url_https'] . "product.php?id=" . $this->id . '&_s=' . $this->seed);
            exit(0);
        }
    }

    private function generateGoogleAnalyticCode() {
        global $site;

        if ($site['multi_domain']['google_analytics_code']) {
            echo $site['multi_domain']['google_analytics_code'];

            $sql_67 = "SELECT sum(1) as 'matches' FROM emailreport_funnellist fl
            JOIN emailreport_sectionset ss ON fl.section_id = ss.id
            WHERE funnelid = {$this->flowinfo["flowid"]} AND ss.name like \"%67%\"";

                    $sql_smma = "SELECT sum(1) as 'matches' FROM emailreport_funnellist fl
            JOIN emailreport_sectionset ss ON fl.section_id = ss.id
            WHERE funnelid = {$this->flowinfo["flowid"]} AND lower(ss.name) like \"%(smma|social)%\"";

                    $sql_aire = "SELECT sum(1) as 'matches' FROM emailreport_funnellist fl
            JOIN emailreport_sectionset ss ON fl.section_id = ss.id
            WHERE funnelid = {$this->flowinfo["flowid"]} AND lower(ss.name) like \"%(aire|real|estate)%\"";

                    $sql_acc = "SELECT sum(1) as 'matches' FROM emailreport_funnellist fl
            JOIN emailreport_sectionset ss ON fl.section_id = ss.id
            WHERE funnelid = {$this->flowinfo["flowid"]} AND lower(ss.name) like \"%(accelerator)%\"";


                    $sql_ceo = "SELECT sum(1) as 'matches' FROM emailreport_funnellist fl
            JOIN emailreport_sectionset ss ON fl.section_id = ss.id
            WHERE funnelid = {$this->flowinfo["flowid"]} AND lower(ss.name) like \"%(ceo|travel)%\"";

            $eventCategory = "LandingPage";
            $eventAction = "Visit";
            $eventLabel = "Step $this->step";
            $eventValue = $this->flowinfo["flowid"];
            if (!is_null(db_res($sql_67)->fetchColumn())) {
                $eventAction = "67Steps";
            } else if (!is_null(db_res($sql_smma)->fetchColumn())) {
                $eventAction = "SMMA";
            } else if (!is_null(db_res($sql_aire)->fetchColumn())) {
                $eventAction = "AIRE";
            } else if (!is_null(db_res($sql_acc)->fetchColumn())) {
                $eventAction = "Accelerator";
            } else if (!is_null(db_res($sql_ceo)->fetchColumn())) {
                $eventAction = "TravelCEO";
            }

            ?>
            <script>
                ga('send', {
                    hitType: 'event',
                    eventCategory: <?=json_encode($eventCategory)?>,
                    eventAction: <?=json_encode($eventAction)?>,
                    eventLabel: <?=json_encode($eventLabel)?>,
                    eventValue: <?=json_encode($eventValue)?>
                });
            </script>
            <?php
        }
    }

    private function generatePixelFireCode() {
        global $site;

        if ($this->userid > 0 && !$_COOKIE['pixel_fire']) {
            $profile_arr = db_arr(sprintf("select Sex,DateOfBirth from Profiles where ID='%d'", $this->userid));

            foreach ($site['multi_domain'] as $key => $value) {
                $findPixelJoin = strpos($key, 'pixel_join_');
                if ($findPixelJoin !== false) {
                    $am = substr($key, 11);
                    if ($am != 0 AND $am + 0 == $am AND $value != '') {
                        $condition = unserialize($site['multi_domain']["pixel_join_condition_$am"]);

                        if (checkPixelCondition($condition, $profile_arr)) {
                            echo $value;
                        }
                    }
                }
            }

            setcookie("pixel_fire", $_COOKIE['pixel_fire'], time() - 3600, '/');
            setcookie("pixel_fire", '1', time() + 10800, '/');
        }
    }

    private function generateFlowChatCode() {

        if ($this->subflowid) {
            $chat = new flow_chat();
            echo $chat->getChatCode($this->subflowid, 'vsl');
        }
    }

    private function setAdditionalCookies() {
        global $site;

        $s = $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
        if (!$_COOKIE['adregisterurl']) {
            setcookie("adregisterurl", $s, time() + 10800, '/');
        }

        if (stripos($_SERVER['HTTP_REFERER'], $site['multi_domain']['name']) === FALSE && !$_COOKIE['join_referer']) {
            setcookie("join_referer", '', time() - 60, '/');
            setcookie("join_referer", $_SERVER['HTTP_REFERER'], time() + 24 * 3600, '/');
        }
    }

    public function start()
    {
        $this->determineFlowId();
        $this->setAffCookie();
        $this->getUserIdFromCookie();

        $this->parentflow = db_arr(sprintf("select * from `flow_parentflow` where `name`='%s'", addslashes($this->id)));
        $this->visible = $this->parentflow['visible'];
        $this->step = $_REQUEST['_m'] ? $_REQUEST['_m'] : 1;

        $this->checkVisibility();

        //record payment url history
        $_SESSION['pay_url_history'] = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];

        $this->flowlogic = new FlowTest($this->id, $this->userid);

        $this->chooseSubFlow();

        $this->getMultiProducts();
        $this->generateNewSubscriptionInputs();

        $this->calculateInitPrice();

        $initStep = $this->getInitStep();

        if (!$initStep) {
            $this->redirectToOtherPages();
        } else {
            $this->recordStepTrack();
            $this->getRegionInfo();
            $this->getMediaAndHeadlineConfig();
            $this->render($initStep);
        }

        $this->generateGoogleAnalyticCode();
        $this->generatePixelFireCode();
        $this->generateFlowChatCode();
        $this->setAdditionalCookies();
    }
}

$funnelFlow = new FunnelFlow();
$funnelFlow->start();
