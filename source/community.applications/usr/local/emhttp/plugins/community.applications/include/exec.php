<?PHP
###############################################################
#                                                             #
# Community Applications copyright 2015-2020, Andrew Zawadzki #
#                    All Rights Reserved                      #
#                                                             #
###############################################################

require_once "/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php"; # must be first include due to paths defined
require_once "/usr/local/emhttp/plugins/community.applications/include/paths.php";
require_once "/usr/local/emhttp/plugins/community.applications/include/helpers.php";
require_once "/usr/local/emhttp/plugins/community.applications/skins/Narrow/skin.php";
require_once "/usr/local/emhttp/plugins/dynamix/include/Wrappers.php";
require_once "/usr/local/emhttp/plugins/dynamix.plugin.manager/include/PluginHelpers.php";

$unRaidSettings = parse_ini_file($caPaths['unRaidVersion']);

################################################################################
# Set up any default settings (when not explicitely set by the settings module #
################################################################################

$caSettings = parse_plugin_cfg("community.applications");

$caSettings['maxPerPage']    = isMobile() ? 12 : 24;
$caSettings['unRaidVersion'] = $unRaidSettings['version'];
$caSettings['timeNew']       = "-10 years";
if ( ! is_file($caPaths['warningAccepted']) )
	$caSettings['NoInstalls'] = true;

$DockerClient = new DockerClient();
$DockerTemplates = new DockerTemplates();

if ( is_file("/var/run/dockerd.pid") && is_dir("/proc/".@file_get_contents("/var/run/dockerd.pid")) ) {
	$caSettings['dockerRunning'] = true;
	$dockerRunning = $DockerClient->getDockerContainers();
} else {
	$caSettings['dockerSearch'] = "no";
	unset($caSettings['dockerRunning']);
	$dockerRunning = array();
}

@mkdir($caPaths['tempFiles'],0777,true);

if ( !is_dir($caPaths['templates-community']) ) {
	@mkdir($caPaths['templates-community'],0777,true);
	@unlink($caPaths['community-templates-info']);
}

############################################
##                                        ##
## BEGIN MAIN ROUTINES CALLED BY THE HTML ##
##                                        ##
############################################

switch ($_POST['action']) {

######################################################################################
# get_content - get the results from templates according to categories, filters, etc #
######################################################################################
case 'get_content':
	$filter      = getPost("filter",false);
	$category    = "/".getPost("category",false)."/i";
	$newApp      = filter_var(getPost("newApp",false),FILTER_VALIDATE_BOOLEAN);
	$sortOrder   = getSortOrder(getPostArray("sortOrder"));
	$caSettings['startup'] = getPost("startupDisplay",false);

	switch ($category) {
		case "/PRIVATE/i":
			$category = false;
			$displayPrivates = true;
			break;
		case "/DEPRECATED/i":
			$category = false;
			$displayDeprecated = true;
			$noInstallComment = "Deprecated Applications are able to still be installed if you have previously had them installed. New installations of these applications are blocked unless you enable Display Deprecated Applications within CA's General Settings<br><br>";
			break;
		case "/BLACKLIST/i":
			$category = false;
			$displayBlacklisted = true;
			$noInstallComment = "The following applications are blacklisted.  CA will never allow you to install or reinstall these applications<br><br>";
			break;
		case "/INCOMPATIBLE/i":
			$displayIncompatible = true;
			$noInstallComment = "<span class='ca_bold'>While highly not recommended to do</span>, incompatible applications can be installed by enabling Display Incompatible Applications within CA's General Settings<br><br>";
			break;
	}
	$newAppTime = strtotime($caSettings['timeNew']);

	if ( file_exists($caPaths['addConverted']) ) {
		@unlink($caPaths['addConverted']);
		getConvertedTemplates();
	}

	$file = readJsonFile($caPaths['community-templates-info']);
	if ( empty($file)) break;

	if ( $category === "/NONE/i" ) {
		$displayApplications = array();
		if ( count($file) > 200) {
			$appsOfDay = appOfDay($file);

			$displayApplications['community'] = array();
			for ($i=0;$i<$caSettings['maxPerPage'];$i++) {
				if ( ! $appsOfDay[$i]) continue;
				$file[$appsOfDay[$i]]['NewApp'] = ($caSettings['startup'] != "random");
				$displayApplications['community'][] = $file[$appsOfDay[$i]];
			}
			if ( $displayApplications['community'] ) {
				writeJsonFile($caPaths['community-templates-displayed'],$displayApplications);
				$sortOrder['sortBy'] = "noSort";
				$o['display'] = my_display_apps($displayApplications['community'],"1");
				$o['script'] = "$('#templateSortButtons,#sortButtons').hide();enableIcon('#sortIcon',false);";
				postReturn($o);
				break;
			} else {
				switch ($caSettings['startup']) {
					case "onlynew":
						$startupType = "New"; break;
					case "new":
						$startupType = "Updated"; break;
					case "trending":
						$startupType = "Top Performing"; break;
					case "random":
						$startupType = "Random"; break;
					case "upandcoming":
						$startupType = "Trending"; break;
				}

				$o['display'] =  "<br><div class='ca_center'><font size='4' color='purple'><span class='ca_bold'>An error occurred.  Could not find any $startupType Apps</span></font><br><br>";
				$o['script'] = "$('#templateSortButtons,#sortButtons').hide();enableIcon('#sortIcon',false);";
				postReturn($o);
				break;
			}
		}
	}
	$display             = array();
	$official            = array();

	foreach ($file as $template) {
		$template['NoInstall'] = $noInstallComment;

		if ( $displayBlacklisted ) {
			if ( $template['Blacklist'] ) {
				$display[] = $template;
				continue;
			} else continue;
		}
		if ( ! $template['Compatible'] && $displayIncompatible ) {
			$display[] = $template;
			continue;
		}
		if ( $template['Deprecated'] && $displayDeprecated && ! $template['Blacklist']) {
			$display[] = $template;
			continue;
		}
		if ( ($caSettings['hideDeprecated'] == "true") && ($template['Deprecated'] && ! $displayDeprecated) ) continue;
		if ( $displayDeprecated && ! $template['Deprecated'] ) continue;
		if ( ! $template['Displayable'] ) continue;
		if ( $caSettings['hideIncompatible'] == "true" && ! $template['Compatible'] && ! $displayIncompatible) continue;
		if ( $template['Blacklist'] ) continue;

		$name = $template['Name'];

		if ( $template['Plugin'] && file_exists("/var/log/plugins/".basename($template['PluginURL'])) )
			$template['MyPath'] = $template['PluginURL'];

		if ( ($newApp) && ($template['Date'] < $newAppTime) ) continue;
		$template['NewApp'] = $newApp;

		if ( $category && ! preg_match($category,$template['Category'])) continue;
		if ( $displayPrivates && ! $template['Private'] ) continue;

		if ($filter) {
			if ( filterMatch($filter,array($template['Name'])) ) {
				highlight($filter,$template['Name']);
				$template['Description'] = highlight($filter, $template['Description']);
				$template['Author'] = highlight($filter, $template['Author']);
				$template['CardDescription'] = highlight($filter,$template['CardDescription']);
				$searchResults['nameHit'][] = $template;
			} else if ( filterMatch($filter,array($template['Author'],$template['Description'],$template['RepoName'],$template['Category'])) ) {
				$template['Description'] = highlight($filter, $template['Description']);
				$template['Author'] = highlight($filter, $template['Author']);
				$template['CardDescription'] = highlight($filter,$template['CardDescription']);
				$searchResults['anyHit'][] = $template;
			} else continue;
		}

		$display[] = $template;
	}
	if ( $filter ) {
		if ( is_array($searchResults['nameHit']) )
			usort($searchResults['nameHit'],"mySort");
		else
			$searchResults['nameHit'] = array();

		if ( is_array($searchResults['anyHit']) )
			usort($searchResults['anyHit'],"mySort");
		else
			$searchResults['anyHit'] = array();

		$displayApplications['community'] = array_merge($searchResults['nameHit'],$searchResults['anyHit']);
		$sortOrder['sortBy'] = "noSort";
	} else {
		$displayApplications['community'] = $display;
	}

	writeJsonFile($caPaths['community-templates-displayed'],$displayApplications);
	$o['display'] = display_apps();
	if ( count($displayApplications['community']) < 2 )
		$o['script'] = "disableSort();";

	postReturn($o);
	break;

########################################################
# force_update -> forces an update of the applications #
########################################################
case 'force_update':
	$lastUpdatedOld = readJsonFile($caPaths['lastUpdated-old']);

	@unlink($caPaths['lastUpdated']);
	$latestUpdate = download_json($caPaths['application-feed-last-updated'],$caPaths['lastUpdated']);
	if ( ! $latestUpdate['last_updated_timestamp'] )
		$latestUpdate = download_json($caPaths['application-feed-last-updatedBackup'],$caPaths['lastUpdated']);

	if ( ! $latestUpdate['last_updated_timestamp'] ) {
		$latestUpdate['last_updated_timestamp'] = INF;
		$badDownload = true;
		@unlink($caPaths['lastUpdated']);
	}

	if ( $latestUpdate['last_updated_timestamp'] > $lastUpdatedOld['last_updated_timestamp'] ) {
		if ( $latestUpdate['last_updated_timestamp'] != INF )
			copy($caPaths['lastUpdated'],$caPaths['lastUpdated-old']);

		if ( ! $badDownload )
			@unlink($caPaths['community-templates-info']);
	}

	if (!file_exists($caPaths['community-templates-info'])) {
		$updatedSyncFlag = true;
		DownloadApplicationFeed();
		if (!file_exists($caPaths['community-templates-info'])) {
			$tmpfile = randomFile();
			download_url($caPaths['PublicServiceAnnouncement'],$tmpfile,false,10);
			$publicServiceAnnouncement = trim(@file_get_contents($tmpfile));
			@unlink($tmpfile);
			$o['script'] = "$('.startupButton,.caMenu,.menuHeader').hide();$('.caRelated').show();";
			$o['data'] =  "<div class='ca_center'><font size='4'><strong>Download of appfeed failed.</strong></font><font size='3'><br><br>Community Applications <span class='ca_italic'><span class='ca_bold'>requires</span></span> your server to have internet access.  The most common cause of this failure is a failure to resolve DNS addresses.  You can try and reset your modem and router to fix this issue, or set static DNS addresses (Settings - Network Settings) of <span class='ca_bold'>208.67.222.222 and 208.67.220.220</span> and try again.<br><br>Alternatively, there is also a chance that the server handling the application feed is temporarily down.  You can check the server status by clicking <a href='https://www.githubstatus.com/' target='_blank'>HERE</a>";
			$tempFile = @file_get_contents($caPaths['appFeedDownloadError']);
			$downloaded = @file_get_contents($tempFile);
			if (strlen($downloaded) > 100)
				$o['data'] .= "<font size='2' color='red'><br><br>It *appears* that a partial download of the application feed happened (or is malformed), therefore it is probable that the application feed is temporarily down.  Please try again later)</font>";

			$o['data'] .=  "<div class='ca_center'>Last JSON error Recorded: ";
			$jsonDecode = json_decode($downloaded,true);
			$o['data'] .= json_last_error_msg();
			if ( $publicServiceAnnouncement )
				$o['data'] .= "<br><font size='3' color='purple'>$publicServiceAnnouncement</font>";

			$o['data'] .= "</div>";
			@unlink($caPaths['appFeedDownloadError']);
			@unlink($caPaths['community-templates-info']);
			postReturn($o);
			break;
		}
	}
	getConvertedTemplates();
	moderateTemplates();
	$currentServer = file_get_contents($caPaths['currentServer']);
	postReturn(['status'=>"ok",'script'=>"feedWarning('$currentServer');"]);
	break;

####################################################################################
# display_content - displays the templates according to view mode, sort order, etc #
####################################################################################
case 'display_content':
	$sortOrder = getSortOrder(getPostArray('sortOrder'));
	$pageNumber = getPost("pageNumber","1");
	$startup = getPost("startup",false);
	$selectedApps = json_decode(getPost("selected",false),true);

	$o['display'] = file_exists($caPaths['community-templates-displayed']) ? display_apps($pageNumber,$selectedApps,$startup) : "";
	$displayedApps = readJsonFile($caPaths['community-templates-displayed']);
	if ( ! is_array($displayedApps['community']) || count($displayedApps['community']) < 2)
		$o['script'] = "disableSort();";

	postReturn($o);
	break;

#######################################################################
# convert_docker - called when system adds a container from dockerHub #
#######################################################################
case 'convert_docker':
	$dockerID = getPost("ID","");

	$file = readJsonFile($caPaths['dockerSearchResults']);
	$docker = $file['results'][$dockerID];
	$docker['Description'] = str_replace("&", "&amp;", $docker['Description']);
	@unlink($caPaths['Dockerfile']);

	$dockerfile['Name'] = $docker['Name'];
	$dockerfile['Support'] = $docker['DockerHub'];
	$dockerfile['Description'] = $docker['Description']."   Converted By Community Applications   Always verify this template (and values) against the dockerhub support page for the container";
	$dockerfile['Overview'] = $dockerfile['Description'];
	$dockerfile['Registry'] = $docker['DockerHub'];
	$dockerfile['Repository'] = $docker['Repository'];
	$dockerfile['BindTime'] = "true";
	$dockerfile['Privileged'] = "false";
	$dockerfile['Networking']['Mode'] = "bridge";
	$dockerfile['Icon'] = "/plugins/dynamix.docker.manager/images/question.png";
	$dockerXML = makeXML($dockerfile);

	$xmlFile = $caPaths['convertedTemplates']."DockerHub/";
	@mkdir($xmlFile,0777,true);
	$xmlFile .= str_replace("/","-",$docker['Repository']).".xml";
	file_put_contents($xmlFile,$dockerXML);
	file_put_contents($caPaths['addConverted'],"Dante");
	postReturn(['xml'=>$xmlFile]);
	break;

#########################################################
# search_dockerhub - returns the results from dockerHub #
#########################################################
case 'search_dockerhub':
	$filter     = getPost("filter","");
	$pageNumber = getPost("page","1");
	$sortOrder  = getSortOrder(getPostArray('sortOrder'));

	$communityTemplates = readJsonFile($caPaths['community-templates-info']);
	$filter = str_replace(" ","%20",$filter);
	$jsonPage = shell_exec("curl -s -X GET 'https://registry.hub.docker.com/v1/search?q=$filter&page=$pageNumber'");
	$pageresults = json_decode($jsonPage,true);
	$num_pages = $pageresults['num_pages'];

	if ($pageresults['num_results'] == 0) {
		$o['display'] = "<div class='ca_NoDockerAppsFound'></div>";
		$o['script'] = "$('#dockerSearch').hide();";
		postReturn($o);
		@unlink($caPaths['dockerSerchResults']);
		break;
	}

	$i = 0;
	foreach ($pageresults['results'] as $result) {
		unset($o);
		$o['Repository'] = $result['name'];
		$details = explode("/",$result['name']);
		$o['Author'] = $details[0];
		$o['Name'] = $details[1];
		$o['Description'] = $result['description'];
		$o['CardDescription'] = (strlen($o['Description']) > 240) ? substr($o['Description'],0,240)." ..." : $o['Description'];
		$o['Automated'] = $result['is_automated'];
		$o['Stars'] = $result['star_count'];
		$o['Official'] = $result['is_official'];
		$o['Trusted'] = $result['is_trusted'];
		if ( $o['Official'] ) {
			$o['DockerHub'] = "https://hub.docker.com/_/".$result['name']."/";
			$o['Name'] = $o['Author'];
		} else
			$o['DockerHub'] = "https://hub.docker.com/r/".$result['name']."/";

		$o['ID'] = $i;
		$searchName = str_replace("docker-","",$o['Name']);
		$searchName = str_replace("-docker","",$searchName);

		$dockerResults[$i] = $o;
		$i=++$i;
	}
	$dockerFile['num_pages'] = $num_pages;
	$dockerFile['page_number'] = $pageNumber;
	$dockerFile['results'] = $dockerResults;

	writeJsonFile($caPaths['dockerSearchResults'],$dockerFile);
	postReturn(['display'=>displaySearchResults($pageNumber)]);
	break;

#####################################################################
# dismiss_warning - dismisses the warning from appearing at startup #
#####################################################################
case 'dismiss_warning':
	file_put_contents($caPaths['warningAccepted'],"warning dismissed");
	postReturn(['status'=>"warning dismissed"]);
	break;

###############################################################
# Displays the list of installed or previously installed apps #
###############################################################
case 'previous_apps':
	$installed = getPost("installed","");
	$dockerUpdateStatus = readJsonFile($caPaths['dockerUpdateStatus']);
	$info = $caSettings['dockerRunning'] ? $DockerClient->getDockerContainers() : array();

	$file = readJsonFile($caPaths['community-templates-info']);

# $info contains all installed containers
# now correlate that to a template;
# this section handles containers that have not been renamed from the appfeed
if ( $caSettings['dockerRunning'] ) {
	$all_files = glob("{$caPaths['dockerManTemplates']}/*.xml");
	$all_files = $all_files ?: array();

	if ( $installed == "true" ) {
		foreach ($info as $installedDocker) {
			$installedImage = $installedDocker['Image'];
			$installedName = $installedDocker['Name'];
			if ( startsWith($installedImage,"library/") ) # official images are in DockerClient as library/mysql eg but template just shows mysql
				$installedImage = str_replace("library/","",$installedImage);

			foreach ($file as $template) {
				if ( $installedName == $template['Name'] ) {
					$template['testrepo'] = $installedImage;
					if ( startsWith($installedImage,$template['Repository']) ) {
						$template['Uninstall'] = true;
						$template['MyPath'] = $template['Path'];
						if ( $dockerUpdateStatus[$installedImage]['status'] == "false" || $dockerUpdateStatus[$template['Name']] == "false" ) {
							$template['UpdateAvailable'] = true;
							$template['FullRepo'] = $installedImage;
						}
						if ($template['Blacklist'] ) continue;

						$displayed[] = $template;
						break;
					}
				}
			}
		}
# handle renamed containers
		foreach ($all_files as $xmlfile) {
			$o = readXmlFile($xmlfile);
			$o['Description'] = fixDescription($o['Description']);
			$o['Overview'] = fixDescription($o['Overview']);
			$o['MyPath'] = $xmlfile;
			$o['UnknownCompatible'] = true;

			$flag = false;
			$containerID = false;
			foreach ($file as $templateDocker) {
# use startsWith to eliminate any version tags (:latest)
				if ( startsWith($templateDocker['Repository'], $o['Repository']) ) {
					if ( $templateDocker['Name'] == $o['Name'] ) {
						$flag = true;
						$containerID = $template['ID'];
						break;
					}
				}
			}
			if ( ! $flag ) {
				$runningflag = false;
				foreach ($info as $installedDocker) {
					$installedImage = $installedDocker['Image'];
					$installedName = $installedDocker['Name'];
					if ( startsWith($installedImage, $o['Repository']) ) {
						if ( $installedName == $o['Name'] ) {
							$runningflag = true;
							$searchResult = searchArray($file,'Repository',$o['Repository']);
							if ( $searchResult !== false ) {
								$tempPath = $o['MyPath'];
								$containerID = $file[$searchResult]['ID'];
								$o = $file[$searchResult];
								$o['Name'] = $installedName;
								$o['MyPath'] = $tempPath;
								$o['SortName'] = $installedName;
								if ( $dockerUpdateStatus[$installedImage]['status'] == "false" || $dockerUpdateStatus[$template['Name']] == "false" ) {
									$o['UpdateAvailable'] = true;
									$o['FullRepo'] = $installedImage;
								}
							}
							break;;
						}
					}
				}
				if ( $runningflag ) {
					$o['Uninstall'] = true;
					$o['ID'] = $containerID;
					if ( $o['Blacklist'] ) 	continue;

					# handle a PR from LT where it is possible for an identical template (xml) to be present twice, with different filenames.
					# Without this, an app could appear to be shown in installed apps twice
					$fat32Fix[$searchResult]++;
					if ($fat32Fix[$searchResult] > 1) continue;
					$displayed[] = $o;
				}
			}
		}
	} else {
# now get the old not installed docker apps
		foreach ($all_files as $xmlfile) {
			$o = readXmlFile($xmlfile);
			if ( ! $o ) continue;
			$o['Description'] = fixDescription($o['Description']);
			$o['Overview'] = fixDescription($o['Overview']);
			$o['MyPath'] = $xmlfile;
			$o['UnknownCompatible'] = true;
			$o['Removable'] = true;
# is the container running?

			$flag = false;
			foreach ($info as $installedDocker) {
				$installedImage = $installedDocker['Image'];
				$installedName = $installedDocker['Name'];
				if ( startsWith($installedImage, $o['Repository']) ) {
					if ( $installedName == $o['Name'] ) {
						$flag = true;
						continue;
					}
				}
			}
			if ( ! $flag ) {
# now associate the template back to a template in the appfeed
				foreach ($file as $appTemplate) {
					if ($appTemplate['Repository'] == $o['Repository']) {
						$tempPath = $o['MyPath'];
						$tempName = $o['Name'];
						$o = $appTemplate;
						$o['Removable'] = true;
						$o['MyPath'] = $tempPath;
						$o['Name'] = $tempName;
						$o['SortName'] = $o['Name'];
						break;
					}
				}

				if ( ! $o['Blacklist'] )
					$displayed[] = $o;
			}
		}
	}
}
# Now work on plugins
	if ( $installed == "true" ) {
		foreach ($file as $template) {
			if ( ! $template['Plugin'] ) continue;

			$filename = pathinfo($template['Repository'],PATHINFO_BASENAME);

			if ( checkInstalledPlugin($template) ) {
				if ( $template['Blacklist'] ) continue;

				$template['MyPath'] = "/var/log/plugins/$filename";
				$template['Uninstall'] = true;
				$displayed[] = $template;
			}
		}
	} else {
		$all_plugs = glob("/boot/config/plugins-removed/*.plg");

		foreach ($all_plugs as $oldplug) {
			foreach ($file as $template) {
				if ( basename($oldplug) == basename($template['Repository']) ) {
					if ( ! file_exists("/boot/config/plugins/".basename($oldplug)) ) {
						if ( $template['Blacklist'] || ( ($caSettings['hideIncompatible'] == "true") && (! $template['Compatible']) ) ) continue;

						if ( strtolower(trim($template['PluginURL'])) != strtolower(trim(plugin("pluginURL","$oldplug"))) ) {
							if ( strtolower(trim($template['PluginURL'])) != strtolower(trim(str_replace("raw.github.com","raw.githubusercontent.com",plugin("pluginURL",$oldplug)))) )
								continue;
						}
						$template['Removable'] = true;
						$template['MyPath'] = $oldplug;

						$displayed[] = $template;
						break;
					}
				}
			}
		}
	}
	$displayedApplications['community'] = $displayed;
	writeJsonFile($caPaths['community-templates-displayed'],$displayedApplications);
	postReturn(['status'=>"ok"]);
	break;

####################################################################################
# Removes an app from the previously installed list (ie: deletes the user template #
####################################################################################
case 'remove_application':
	$application = getPost("application","");
	if ( pathinfo($application,PATHINFO_EXTENSION) == "xml" || pathinfo($application,PATHINFO_EXTENSION) == "plg" )
		@unlink($application);

	postReturn(['status'=>"ok"]);
	break;

###################################################################################
# Checks for an update still available (to update display) after update installed #
###################################################################################
case 'updatePLGstatus':
	$filename = getPost("filename","");
	$displayed = readJsonFile($caPaths['community-templates-displayed']);
	$superCategories = array_keys($displayed);
	foreach ($superCategories as $category) {
		foreach ($displayed[$category] as $template) {
			if ( strpos($template['PluginURL'],$filename) )
				$template['UpdateAvailable'] = checkPluginUpdate($filename);

			$newDisplayed[$category][] = $template;
		}
	}
	writeJsonFile($caPaths['community-templates-displayed'],$newDisplayed);
	postReturn(['status'=>"ok"]);
	break;

#######################
# Uninstalls a docker #
#######################
case 'uninstall_docker':
	$application = getPost("application","");

# get the name of the container / image
	$doc = new DOMDocument();
	$doc->load($application);
	$containerName  = stripslashes($doc->getElementsByTagName( "Name" )->item(0)->nodeValue);

	$dockerInfo = $DockerClient->getDockerContainers();
	$container = searchArray($dockerInfo,"Name",$containerName);

	if ( $dockerRunning[$container]['Running'] )
		myStopContainer($dockerRunning[$container]['Id']);

	$DockerClient->removeContainer($containerName,$dockerRunning[$container]['Id']);
	if ( $caSettings['deleteImage'] !== "no" )
		$DockerClient->removeImage($dockerRunning[$container]['ImageId']);

	postReturn(['status'=>"Uninstalled"]);
	break;

##################################################
# Pins / Unpins an application for later viewing #
##################################################
case "pinApp":
	$repository = getPost("repository","oops");
	$name = getPost("name","oops");
	$pinnedApps = readJsonFile($caPaths['pinnedV2']);
	$pinnedApps["$repository&$name"] = $pinnedApps["$repository&$name"] ? false : "$repository&$name";
	writeJsonFile($caPaths['pinnedV2'],$pinnedApps);
	break;

####################################
# Displays the pinned applications #
####################################
case "pinnedApps":
	$pinnedApps = readJsonFile($caPaths['pinnedV2']);
	$file = readJsonFile($caPaths['community-templates-info']);

	foreach ($pinnedApps as $pinned) {
		$startIndex = 0;
		$search = explode("&",$pinned);
		file_put_contents("/tmp/blah",print_r($search,true),FILE_APPEND);
		for ($i=0;$i<10;$i++) {
			$index = searchArray($file,"Repository",$search[0],$startIndex);
			if ( $index !== false ) {
				if ( $file[$index]['Blacklist'] ) { #This handles things like duplicated templates
					$startIndex = $index + 1;
					continue;
				}
				if ($file[$index]['SortName'] !== $search[1]) {
					$startIndex = $index +1;
					continue;
				}
				$displayed[] = $file[$index];
				break;
			}
		}
	}
	$displayedApplications['community'] = $displayed;
	$displayedApplications['pinnedFlag']  = true;
	writeJsonFile($caPaths['community-templates-displayed'],$displayedApplications);
	postReturn(["status"=>"ok"]);
	break;

################################################
# Displays the possible branch tags for an app #
################################################
case 'displayTags':
	$leadTemplate = getPost("leadTemplate","oops");
	postReturn(['tags'=>formatTags($leadTemplate)]);
	break;

###########################################
# Displays The Statistics For The Appfeed #
###########################################
case 'statistics':
	$statistics = download_json($caPaths['statisticsURL'],$caPaths['statistics']);
	download_json($caPaths['moderationURL'],$caPaths['moderation']);
	$statistics['totalModeration'] = count(readJsonFile($caPaths['moderation']));
	$repositories = download_json($caPaths['community-templates-url'],$caPaths['Repositories']);
	$templates = readJsonFile($caPaths['community-templates-info']);
	pluginDupe($templates);
	$invalidXML = readJsonFile($caPaths['invalidXML_txt']);

	foreach ($templates as $template) {
		if ( $template['Deprecated'] && ! $template['Blacklist'] ) $statistics['totalDeprecated']++;

		if ( ! $template['Compatible'] ) $statistics['totalIncompatible']++;

		if ( $template['Blacklist'] ) $statistics['blacklist']++;

		if ( $template['Private'] && ! $template['Blacklist']) {
			if ( ! ($caSettings['hideDeprecated'] == 'true' && $template['Deprecated']) )
				$statistics['private']++;
		}
		if ( ! $template['PluginURL'] && ! $template['Repository'] )
			$statistics['invalidXML']++;
		else {
			if ( $template['PluginURL'] )
				$statistics['plugin']++;
			else
				$statistics['docker']++;
		}
	}
	$statistics['totalApplications'] = $statistics['plugin']+$statistics['docker'];
	if ( $statistics['fixedTemplates'] )
		writeJsonFile($caPaths['fixedTemplates_txt'],$statistics['fixedTemplates']);
	else
		@unlink($caPaths['fixedTemplates_txt']);

	if ( is_file($caPaths['lastUpdated-old']) )
		$appFeedTime = readJsonFile($caPaths['lastUpdated-old']);

	$updateTime = date("F d, Y @ g:i a",$appFeedTime['last_updated_timestamp']);
	$defaultArray = Array('caFixed' => 0,'totalApplications' => 0, 'repository' => 0, 'docker' => 0, 'plugin' => 0, 'invalidXML' => 0, 'blacklist' => 0, 'totalIncompatible' =>0, 'totalDeprecated' => 0, 'totalModeration' => 0, 'private' => 0, 'NoSupport' => 0);
	$statistics = array_merge($defaultArray,$statistics);

	foreach ($statistics as &$stat) {
		if ( ! $stat ) $stat = "0";
	}

	$totalCA = exec("du -h -s /usr/local/emhttp/plugins/community.applications/");
	$totalTmp = exec("du -h -s /tmp/community.applications/");
	$totalFlash = exec("du -h -s /boot/config/plugins/community.applications/");
	$memCA = explode("\t",$totalCA);
	$memTmp = explode("\t",$totalTmp);
	if ( ! $memIcon[0] ) $memIcon[0] = "0K";
	$memFlash = explode("\t",$totalFlash);

	$currentServer = @file_get_contents($caPaths['currentServer']);
	if ( $currentServer != "Primary Server" )
		$currentServer = "<i class='fa fa-exclamation-triangle ca_serverWarning' aria-hidden='true'></i> $currentServer";

	$statistics['invalidXML'] = @count($invalidXML) ?: "unknown";
	$statistics['repositories'] = @count($repositories) ?: "unknown";
	$o = <<<EOF
		<div style='height:auto;overflow:scroll; overflow-x:hidden; overflow-y:hidden;margin:auto;width:700px;'>
		<table style='margin-top:1rem;'>
		<tr style='height:6rem;'><td colspan='2'><div class='ca_center'><i class='fa fa-users' style='font-size:6rem;'></i></td></tr>
		<tr><td colspan='2'><div class='ca_center'><font size='5rem;'>Community Applications</font></div></td></tr>
		<tr><td class='ca_table'>Last Change To Application Feed</td><td class='ca_stat'>$updateTime<br>$currentServer active</td></tr>
		<tr><td class='ca_table'>Number Of Docker Applications</td><td class='ca_stat'>{$statistics['docker']}</td></tr>
		<tr><td class='ca_table'>Number Of Plugin Applications</td><td class='ca_stat'>{$statistics['plugin']}</td></tr>
		<tr><td class='ca_table'>Number Of Templates</td><td class='ca_stat'>{$statistics['totalApplications']}</td></tr>
		<tr><td class='ca_table'><a onclick='showModeration("Repository","Repository List");' style='cursor:pointer;'>Number Of Repositories</a></td><td class='ca_stat'>{$statistics['repositories']}</td></tr>
		<tr><td class='ca_table'><a data-category='PRIVATE' onclick='showSpecialCategory(this);' style='cursor:pointer;'>Number Of Private Docker Applications</a></td><td class='ca_stat'>{$statistics['private']}</td></tr>
		<tr><td class='ca_table'><a onclick='showModeration("Invalid","All Invalid Templates Found");' style='cursor:pointer'>Number Of Invalid Templates</a></td><td class='ca_stat'>{$statistics['invalidXML']}</td></tr>
		<tr><td class='ca_table'><a onclick='showModeration("Fixed","Template Errors");' style='cursor:pointer'>Number Of Template Errors</a></td><td class='ca_stat'>{$statistics['caFixed']}+</td></tr>
		<tr><td class='ca_table'><a data-category='BLACKLIST' onclick='showSpecialCategory(this);' style='cursor:pointer'>Number Of Blacklisted Apps</a></td><td class='ca_stat'>{$statistics['blacklist']}</td></tr>
		<tr><td class='ca_table'><a data-category='INCOMPATIBLE' onclick='showSpecialCategory(this);' style='cursor:pointer'>Number Of Incompatible Applications</a></td><td class='ca_stat'>{$statistics['totalIncompatible']}</td></tr>
		<tr><td class='ca_table'><a data-category='DEPRECATED' onclick='showSpecialCategory(this);' style='cursor:pointer'>Number Of Deprecated Applications</a></td><td class='ca_stat'>{$statistics['totalDeprecated']}</td></tr>
		<tr><td class='ca_table'><a onclick='showModeration("Moderation","All Moderation Entries");' style='cursor:pointer'>Number Of Moderation Entries</a></td><td class='ca_stat'>{$statistics['totalModeration']}+</td></tr>
		<tr><td class='ca_table'>Memory Usage (CA / DataFiles / Flash)</td><td class='ca_stat'>{$memCA[0]} / {$memTmp[0]} / {$memFlash[0]}</td></tr>
		<tr><td class='ca_table'><a href='{$caPaths['application-feed']}' target='_blank'>Primary Server</a> / <a href='{$caPaths['application-feedBackup']}' target='_blank'> Backup Server</a></td></tr>
		</table>
		<div class='ca_center'><a href='https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=7M7CBCVU732XG' target='_blank'><img style='height:2.5rem;' src='https://github.com/Squidly271/community.applications/raw/master/webImages/donate-button.png'></a></div>
		<div class='ca_center'>Ensuring only safe applications are present is a full time job</div><br>
		<div class='ca_center'><a href='https://forums.unraid.net/topic/87144-ca-application-policies/' target='_blank'>Application Policy</a></div>
EOF;
	postReturn(['statistics'=>$o]);
	break;

#######################################
# Removes a private app from the list #
#######################################
case 'removePrivateApp':
	$path = getPost("path",false);

	if ( ! $path || pathinfo($path,PATHINFO_EXTENSION) != "xml") {
		postReturn(["error"=>"Something went wrong-> not an xml file: $path"]);
		break;
	}
	$templates = readJsonFile($caPaths['community-templates-info']);
	$displayed = readJsonFile($caPaths['community-templates-displayed']);
	foreach ( $displayed as &$displayType ) {
		foreach ( $displayType as &$display ) {
			if ( $display['Path'] == $path )
				$display['Blacklist'] = true;
		}
	}
	foreach ( $templates as &$template ) {
		if ( $template['Path'] == $path )
			$template['Blacklist'] = true;
	}
	writeJsonFile($caPaths['community-templates-info'],$templates);
	writeJsonFile($caPaths['community-templates-displayed'],$displayed);
	@unlink($path);
	postReturn(["status"=>"ok"]);
	break;

####################################################
# Creates the entries for autocomplete on searches #
####################################################
case 'populateAutoComplete':
	$templates = readJsonFile($caPaths['community-templates-info']);
	$autoComplete = array_map(function($x){return str_replace(":","",$x['Cat']);},readJsonFile($caPaths['categoryList']));
	foreach ($templates as $template) {
		if ( ! $template['Blacklist'] && ! ($template['Deprecated'] && $caSettings['hideDeprecated'] == "true") && ($template['Compatible'] || $caSettings['hideIncompatible'] != "true") ) {
			$autoComplete[strtolower($template['Name'])] = $template['Name'];
			$autoComplete[strtolower($template['Author'])] = $template['Author'];
			$autoComplete[$template['Repo']] = $template['Repo'];
		}
	}
	postReturn(['autocomplete'=>array_filter(array_values($autoComplete))]);
	break;

##########################
# Displays the changelog #
##########################
case 'caChangeLog':
	require_once("webGui/include/Markdown.php");
	$o = "<div style='margin:auto;width:500px;'>";
	$o .= "<div class='ca_center'><font size='4rem'>Community Applications Changelog</font></div><br><br>";
	postReturn(["changelog"=>$o.Markdown(plugin("changes","/var/log/plugins/community.applications.plg"))]);
	break;

###############################
# Populates the category list #
###############################
case 'get_categories':
	$categories = readJsonFile($caPaths['categoryList']);
	if ( ! is_array($categories) ) {
		$cat = "<span class='ca_fa-warning'></span> Category list N/A<br><br>";
		break;
	} else {
		foreach ($categories as $category) {
			$cat .= "<li class='categoryMenu caMenuItem' data-category='{$category['Cat']}'>{$category['Des']}</li>";
			if (is_array($category['Sub'])) {
				$cat .= "<ul class='subCategory'>";
				foreach($category['Sub'] as $subcategory) {
					$cat .= "<li class='categoryMenu caMenuItem' data-category='{$subcategory['Cat']}'>{$subcategory['Des']}</li>";
				}
				$cat .= "</ul>";
			}
		}
		$templates = readJsonFile($caPaths['community-templates-info']);
		foreach ($templates as $template) {
			if ($template['Private'] == true && ! $template['Blacklist']) {
				$cat .= "<li class='categoryMenu caMenuItem' data-category='PRIVATE'>Private Apps</li>";
				break;
			}
		}
	}
	postReturn(["categories"=>$cat]);
	break;

##############################
# Get the html for the popup #
##############################
case 'getPopupDescription':
	$appNumber = getPost("appPath","");
	postReturn(getPopupDescription($appNumber));
	break;

case 'createXML':
	$xmlFile = getPost("xml","");
	if ( ! $xmlFile ) {
		postReturn(["error"=>"CreateXML: XML file was missing"]);
		break;
	}
	$templates = readJsonFile($caPaths['community-templates-info']);
	if ( ! $templates ) {
		postReturn(["error"=>"Create XML: templates file missing or empty"]);
		break;
	}
	if ( !startsWith($xmlFile,"/boot/") ) {
		$index = searchArray($templates,"Path",$xmlFile);
		if ( $index === false ) {
			postReturn(["error"=>"Create XML: couldn't find template with path of $xmlFile"]);
			break;
		}
		$xml = makeXML($templates[$index]);
		@mkdir(dirname($xmlFile));
		file_put_contents($xmlFile,$xml);
	}
	postReturn(["status"=>"ok"]);
	break;

###############################################
# Return an error if the action doesn't exist #
###############################################
default:
	postReturn(["error"=>"Unknown post action {$_POST['action']}"]);
	break;
}
#  DownloadApplicationFeed MUST BE CALLED prior to DownloadCommunityTemplates in order for private repositories to be merged correctly.

function DownloadApplicationFeed() {
	global $caPaths, $caSettings, $statistics;

	exec("rm -rf '{$caPaths['tempFiles']}'");
	@mkdir($caPaths['templates-community'],0777,true);

	$currentFeed = "Primary Server";
	$downloadURL = randomFile();
	$ApplicationFeed = download_json($caPaths['application-feed'],$downloadURL);
	if ( ! is_array($ApplicationFeed['applist']) ) {
		$currentFeed = "Backup Server";
		$ApplicationFeed = download_json($caPaths['application-feedBackup'],$downloadURL);
	}
	@unlink($downloadURL);
	if ( ! is_array($ApplicationFeed['applist']) ) {
		@unlink($caPaths['currentServer']);
		file_put_contents($caPaths['appFeedDownloadError'],$downloadURL);
		return false;
	}
	file_put_contents($caPaths['currentServer'],$currentFeed);
	$i = 0;
	$lastUpdated['last_updated_timestamp'] = $ApplicationFeed['last_updated_timestamp'];
	writeJsonFile($caPaths['lastUpdated-old'],$lastUpdated);
	$myTemplates = array();

	foreach ($ApplicationFeed['applist'] as $o) {
		if ( (! $o['Repository']) && (! $o['Plugin']) ){
			$invalidXML[] = $o;
			continue;
		}
		# Move the appropriate stuff over into a CA data file
		$o['ID']            = $i;
		$o['Displayable']   = true;
		$o['Author']        = getAuthor($o);
		$o['DockerHubName'] = strtolower($o['Name']);
		$o['RepoName']      = $o['Repo'];
		$o['SortAuthor']    = $o['Author'];
		$o['SortName']      = $o['Name'];
		$o['CardDescription'] = (strlen($o['Description']) > 240) ? substr($o['Description'],0,240)." ..." : $o['Description'];
		if ( $o['IconHTTPS'] )
			$o['IconHTTPS'] = $caPaths['iconHTTPSbase'] .$o['IconHTTPS'];

		if ( $o['PluginURL'] ) {
			$o['Author']        = $o['PluginAuthor'];
			$o['Repository']    = $o['PluginURL'];
		}
		$o['Blacklist'] = $o['CABlacklist'] ? true : $o['Blacklist'];
		$o['MinVer'] = max(array($o['MinVer'],$o['UpdateMinVer']));

		$o['Path']          = $caPaths['templates-community']."/".alphaNumeric($o['RepoName'])."/".alphaNumeric($o['Name']);
		if ( file_exists($o['Path'].".xml") ) {
			$o['Path'] .= "(1)";
		}
		$o['Path'] .= ".xml";

		$o = fixTemplates($o);
		if ( ! $o ) continue;

		if ( is_array($o['trends']) && count($o['trends']) > 1 ) {
			$o['trendDelta'] = end($o['trends']) - $o['trends'][0];
			$o['trendAverage'] = array_sum($o['trends'])/count($o['trends']);
		}

		$o['Category'] = str_replace("Status:Beta","",$o['Category']);    # undo changes LT made to my xml schema for no good reason
		$o['Category'] = str_replace("Status:Stable","",$o['Category']);
		$myTemplates[$i] = $o;

		if ( is_array($o['Branch']) ) {
			if ( ! $o['Branch'][0] ) {
				$tmp = $o['Branch'];
				unset($o['Branch']);
				$o['Branch'][] = $tmp;
			}
			foreach($o['Branch'] as $branch) {
				$i = ++$i;
				$subBranch = $o;
				$masterRepository = explode(":",$subBranch['Repository']);
				$o['BranchDefault'] = $masterRepository[1];
				$subBranch['Repository'] = $masterRepository[0].":".$branch['Tag']; #This takes place before any xml elements are overwritten by additional entries in the branch, so you can actually change the repo the app draws from
				$subBranch['BranchName'] = $branch['Tag'];
				$subBranch['BranchDescription'] = $branch['TagDescription'] ? $branch['TagDescription'] : $branch['Tag'];
				$subBranch['Path'] = $caPaths['templates-community']."/".$i.".xml";
				$subBranch['Displayable'] = false;
				$subBranch['ID'] = $i;
				$subBranch['Overview'] = $o['OriginalOverview'] ?: $o['Overview'];
				$subBranch['Description'] = $o['OriginalDescription'] ?: $o['Description'];
				$replaceKeys = array_diff(array_keys($branch),array("Tag","TagDescription"));
				foreach ($replaceKeys as $key) {
					$subBranch[$key] = $branch[$key];
				}
				unset($subBranch['Branch']);
				$myTemplates[$i] = $subBranch;
				$o['BranchID'][] = $i;
//				file_put_contents($subBranch['Path'],makeXML($subBranch));
			}
		}
		unset($o['Branch']);
		$myTemplates[$o['ID']] = $o;
		$i = ++$i;
		if ( $o['OriginalOverview'] ) {
			$o['Overview'] = $o['OriginalOverview'];
			unset($o['OriginalOverview']);
			unset($o['Description']);
		}
		if ( $o['OriginalDescription'] ) {
			$o['Description'] = $o['OriginalDescription'];
			unset($o['OriginalDescription']);
		}
//		$templateXML = makeXML($o);
//		@mkdir(dirname($o['Path']),0777,true);

//		file_put_contents($o['Path'],$templateXML);
	}
	if ( $invalidXML )
		writeJsonFile($caPaths['invalidXML_txt'],$invalidXML);
	else
		@unlink($caPaths['invalidXML_txt']);

	writeJsonFile($caPaths['community-templates-info'],$myTemplates);
	writeJsonFile($caPaths['categoryList'],$ApplicationFeed['categories']);
	return true;
}

function getConvertedTemplates() {
	global $caPaths, $caSettings, $statistics;

# Start by removing any pre-existing private (converted templates)
	$templates = readJsonFile($caPaths['community-templates-info']);

	if ( empty($templates) ) return false;

	foreach ($templates as $template) {
		if ( ! $template['Private'] )
			$myTemplates[] = $template;
	}
	$appCount = count($myTemplates);
	$i = $appCount;
	unset($Repos);

	if ( ! is_dir($caPaths['convertedTemplates']) ) return;

	$privateTemplates = glob($caPaths['convertedTemplates']."*/*.xml");
	foreach ($privateTemplates as $template) {
		$o = readXmlFile($template);
		if ( ! $o['Repository'] ) continue;

		$o['Private']      = true;
		$o['RepoName']     = basename(pathinfo($template,PATHINFO_DIRNAME))." Repository";
		$o['ID']           = $i;
		$o['Displayable']  = true;
		$o['Date']         = ( $o['Date'] ) ? strtotime( $o['Date'] ) : 0;
		$o['SortAuthor']   = $o['Author'];
		$o['Compatible']   = versionCheck($o);
		$o['CardDescription'] = (strlen($o['Description']) > 240) ? substr($o['Description'],0,240)." ..." : $o['Description'];

		$o = fixTemplates($o);
		$myTemplates[$i]  = $o;
		$i = ++$i;
	}
	writeJsonFile($caPaths['community-templates-info'],$myTemplates);
	return true;
}

#############################
# Selects an app of the day #
#############################
function appOfDay($file) {
	global $caPaths,$caSettings,$sortOrder;

	switch ($caSettings['startup']) {
		case "random":
			$oldAppDay = @filemtime($caPaths['appOfTheDay']);
			$oldAppDay = $oldAppDay ?: 1;
			$oldAppDay = intval($oldAppDay / 86400);
			$currentDay = intval(time() / 86400);
			if ( $oldAppDay == $currentDay ) {
				$appOfDay = readJsonFile($caPaths['appOfTheDay']);
				$flag = false;
				foreach ($appOfDay as $testApp) {
					if ( ! checkRandomApp($file[$testApp]) ) {
						$flag = true;
						break;
					}
				}
				if ( $flag )
					unset($app);
			}
			if ( ! $app ) {
				shuffle($file);
				foreach ($file as $template) {
					if ( ! checkRandomApp($template) ) continue;
					$appOfDay[] = $template['ID'];
					if (count($appOfDay) == 25) break;
				}
			}
			writeJsonFile($caPaths['appOfTheDay'],$appOfDay);
			break;
		case "new":
			$sortOrder['sortBy'] = "Date";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			foreach ($file as $template) {
				if ( ! checkRandomApp($template) ) continue;
				$appOfDay[] = $template['ID'];
				if (count($appOfDay) == 25) break;
			}
			break;
		case "onlynew":
			$sortOrder['sortBy'] = "FirstSeen";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			foreach ($file as $template) {
				if ( ! $template['Compatible'] == "true" && $caSettings['hideIncompatible'] == "true" ) continue;
				if ( $template['FirstSeen'] > 1538357652 ) {
					if ( checkRandomApp($template) ) {
						$appOfDay[] = $template['ID'];
						if ( count($appOfDay) == 25 ) break;
					}
				}
			}
			break;
		case "topperforming":
			$sortOrder['sortBy'] = "trending";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			foreach ($file as $template) {
				if ( ! is_array($template['trends']) ) continue;
				if ( count($template['trends']) < 6 ) continue;
				if ( startsWith($template['Repository'],"ich777/steamcmd") ) continue; // because a ton of apps all use the same repo
				if ( $template['trending'] && ($template['downloads'] > 100000) ) {
					if ( checkRandomApp($template) ) {
						$appOfDay[] = $template['ID'];
						if ( count($appOfDay) == 25 ) break;
					}
				}
			}
			break;
		case "trending":
			$sortOrder['sortBy'] = "trendDelta";
			$sortOrder['sortDir'] = "Down";
			usort($file,"mySort");
			foreach ($file as $template) {
				if ( startsWith($template['Repository'],"ich777/steamcmd") ) continue; // because a ton of apps all use the same repo`
				if ( $template['trending'] && ($template['downloads'] > 10000) ) {
					if ( checkRandomApp($template) ) {
						$appOfDay[] = $template['ID'];
						if ( count($appOfDay) == 25 ) break;
					}
				}
			}
			break;
	}
	return $appOfDay ?: array();
}

#####################################################
# Checks selected app for eligibility as app of day #
#####################################################
function checkRandomApp($test) {
	global $caSettings;

	if ( $test['Name'] == "Community Applications" )  return false;
	if ( $test['BranchName'] )                        return false;
	if ( ! $test['Displayable'] )                     return false;
	if ( ! $test['Compatible'] )                      return false;
	if ( $test['Blacklist'] )                         return false;
	if ( $test['Deprecated'] && ( $caSettings['hideDeprecated'] == "true" ) ) return false;

	return true;
}
?>