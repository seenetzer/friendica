<?php

/**
 *
 * This is the POST destination for most all locally posted
 * text stuff. This function handles status, wall-to-wall status, 
 * local comments, and remote coments - that are posted on this site 
 * (as opposed to being delivered in a feed).
 * Also processed here are posts and comments coming through the 
 * statusnet/twitter API. 
 * All of these become an "item" which is our basic unit of 
 * information.
 * Posts that originate externally or do not fall into the above 
 * posting categories go through item_store() instead of this function. 
 *
 */  

require_once('include/crypto.php');
require_once('include/enotify.php');

function item_post(&$a) {

	if((! local_user()) && (! remote_user()))
		return;

	require_once('include/security.php');

	$uid = local_user();

	if(x($_POST,'dropitems')) {
		require_once('include/items.php');
		$arr_drop = explode(',',$_POST['dropitems']);
		drop_items($arr_drop);
		$json = array('success' => 1);
		echo json_encode($json);
		killme();
	}

	call_hooks('post_local_start', $_POST);

	logger('postvars' . print_r($_POST,true));

	$api_source = ((x($_POST,'api_source') && $_POST['api_source']) ? true : false);
	$return_path = ((x($_POST,'return')) ? $_POST['return'] : '');

	/**
	 * Is this a reply to something?
	 */

	$parent = ((x($_POST,'parent')) ? intval($_POST['parent']) : 0);
	$parent_uri = ((x($_POST,'parent_uri')) ? trim($_POST['parent_uri']) : '');

	$parent_item = null;
	$parent_contact = null;
	$thr_parent = '';
	$parid = 0;
	$r = false;

	$preview = ((x($_POST,'preview')) ? intval($_POST['preview']) : 0);

	if($parent || $parent_uri) {

		if(! x($_POST,'type'))
			$_POST['type'] = 'net-comment';

		if($parent) {
			$r = q("SELECT * FROM `item` WHERE `id` = %d LIMIT 1",
				intval($parent)
			);
		}
		elseif($parent_uri && local_user()) {
			// This is coming from an API source, and we are logged in
			$r = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d LIMIT 1",
				dbesc($parent_uri),
				intval(local_user())
			);
		}
		// if this isn't the real parent of the conversation, find it
		if($r !== false && count($r)) {
			$parid = $r[0]['parent'];
			if($r[0]['id'] != $r[0]['parent']) {
				$r = q("SELECT * FROM `item` WHERE `id` = `parent` AND `parent` = %d LIMIT 1",
					intval($parid)
				);
			}
		}

		if(($r === false) || (! count($r))) {
			notice( t('Unable to locate original post.') . EOL);
			if(x($_POST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
		$parent_item = $r[0];
		$parent = $r[0]['id'];

		// multi-level threading - preserve the info but re-parent to our single level threading
		if(($parid) && ($parid != $parent))
			$thr_parent = $parent_uri;

		if($parent_item['contact-id'] && $uid) {
			$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
				intval($parent_item['contact-id']),
				intval($uid)
			);
			if(count($r))
				$parent_contact = $r[0];
		}
	}

	if($parent) logger('mod_post: parent=' . $parent);

	$profile_uid = ((x($_POST,'profile_uid')) ? intval($_POST['profile_uid']) : 0);
	$post_id     = ((x($_POST['post_id']))    ? intval($_POST['post_id'])     : 0);
	$app         = ((x($_POST['source']))     ? strip_tags($_POST['source'])  : '');

	if(! can_write_wall($a,$profile_uid)) {
		notice( t('Permission denied.') . EOL) ;
		if(x($_POST,'return')) 
			goaway($a->get_baseurl() . "/" . $return_path );
		killme();
	}


	// is this an edited post?

	$orig_post = null;

	if($post_id) {
		$i = q("SELECT * FROM `item` WHERE `uid` = %d AND `id` = %d LIMIT 1",
			intval($profile_uid),
			intval($post_id)
		);
		if(! count($i))
			killme();
		$orig_post = $i[0];
	}

	$user = null;

	$r = q("SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
		intval($profile_uid)
	);
	if(count($r))
		$user = $r[0];

	if($orig_post) {
		$str_group_allow   = $orig_post['allow_gid'];
		$str_contact_allow = $orig_post['allow_cid'];
		$str_group_deny    = $orig_post['deny_gid'];
		$str_contact_deny  = $orig_post['deny_cid'];
		$title             = $orig_post['title'];
		$location          = $orig_post['location'];
		$coord             = $orig_post['coord'];
		$verb              = $orig_post['verb'];
		$emailcc           = $orig_post['emailcc'];
		$app			   = $orig_post['app'];

		$body              = escape_tags(trim($_POST['body']));
		$private           = $orig_post['private'];
		$pubmail_enable    = $orig_post['pubmail'];
	}
	else {
		$str_group_allow   = perms2str($_POST['group_allow']);
		$str_contact_allow = perms2str($_POST['contact_allow']);
		$str_group_deny    = perms2str($_POST['group_deny']);
		$str_contact_deny  = perms2str($_POST['contact_deny']);
		$title             = notags(trim($_POST['title']));
		$location          = notags(trim($_POST['location']));
		$coord             = notags(trim($_POST['coord']));
		$verb              = notags(trim($_POST['verb']));
		$emailcc           = notags(trim($_POST['emailcc']));

		$body              = escape_tags(trim($_POST['body']));
		$private = ((strlen($str_group_allow) || strlen($str_contact_allow) || strlen($str_group_deny) || strlen($str_contact_deny)) ? 1 : 0);

		if(($parent_item) && 
			(($parent_item['private']) 
				|| strlen($parent_item['allow_cid']) 
				|| strlen($parent_item['allow_gid']) 
				|| strlen($parent_item['deny_cid']) 
				|| strlen($parent_item['deny_gid'])
			)) {
			$private = 1;
		}
	
		$pubmail_enable    = ((x($_POST,'pubmail_enable') && intval($_POST['pubmail_enable']) && (! $private)) ? 1 : 0);

		// if using the API, we won't see pubmail_enable - figure out if it should be set

		if($api_source && $profile_uid && $profile_uid == local_user() && (! $private)) {
			$mail_disabled = ((function_exists('imap_open') && (! get_config('system','imap_disabled'))) ? 0 : 1);
			if(! $mail_disabled) {
				$r = q("SELECT * FROM `mailacct` WHERE `uid` = %d AND `server` != '' LIMIT 1",
					intval(local_user())
				);
				if(count($r) && intval($r[0]['pubmail']))
					$pubmail_enabled = true;
			}
		}


		if(! strlen($body)) {
			info( t('Empty post discarded.') . EOL );
			if(x($_POST,'return')) 
				goaway($a->get_baseurl() . "/" . $return_path );
			killme();
		}
	}

	if(($api_source) 
		&& (! array_key_exists('allow_cid',$_REQUEST))
		&& (! array_key_exists('allow_gid',$_REQUEST))
		&& (! array_key_exists('deny_cid',$_REQUEST))
		&& (! array_key_exists('deny_gid',$_REQUEST))) {
		$str_group_allow   = $user['allow_gid'];
		$str_contact_allow = $user['allow_cid'];
		$str_group_deny    = $user['deny_gid'];
		$str_contact_deny  = $user['deny_cid'];
	}


	// get contact info for poster

	$author = null;
	$self   = false;

	if(($_SESSION['uid']) && ($_SESSION['uid'] == $profile_uid)) {
		$self = true;
		$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1 LIMIT 1",
			intval($_SESSION['uid'])
		);
	}
	else {
		if((x($_SESSION,'visitor_id')) && (intval($_SESSION['visitor_id']))) {
			$r = q("SELECT * FROM `contact` WHERE `id` = %d LIMIT 1",
				intval($_SESSION['visitor_id'])
			);
		}
	}

	if(count($r)) {
		$author = $r[0];
		$contact_id = $author['id'];
	}

	// get contact info for owner
	
	if($profile_uid == $_SESSION['uid']) {
		$contact_record = $author;
	}
	else {
		$r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1 LIMIT 1",
			intval($profile_uid)
		);
		if(count($r))
			$contact_record = $r[0];
	}



	$post_type = notags(trim($_POST['type']));

	if($post_type === 'net-comment') {
		if($parent_item !== null) {
			if($parent_item['wall'] == 1)
				$post_type = 'wall-comment';
			else
				$post_type = 'remote-comment';
		}
	}

	/**
	 *
	 * When a photo was uploaded into the message using the (profile wall) ajax 
	 * uploader, The permissions are initially set to disallow anybody but the
	 * owner from seeing it. This is because the permissions may not yet have been
	 * set for the post. If it's private, the photo permissions should be set
	 * appropriately. But we didn't know the final permissions on the post until
	 * now. So now we'll look for links of uploaded messages that are in the
	 * post and set them to the same permissions as the post itself.
	 *
	 */

	$match = null;

	if((! $preview) && preg_match_all("/\[img\](.*?)\[\/img\]/",$body,$match)) {
		$images = $match[1];
		if(count($images)) {
			foreach($images as $image) {
				if(! stristr($image,$a->get_baseurl() . '/photo/'))
					continue;
				$image_uri = substr($image,strrpos($image,'/') + 1);
				$image_uri = substr($image_uri,0, strpos($image_uri,'-'));
				if(! strlen($image_uri))
					continue;
				$srch = '<' . intval($profile_uid) . '>';
				$r = q("SELECT `id` FROM `photo` WHERE `allow_cid` = '%s' AND `allow_gid` = '' AND `deny_cid` = '' AND `deny_gid` = ''
					AND `resource-id` = '%s' AND `uid` = %d LIMIT 1",
					dbesc($srch),
					dbesc($image_uri),
					intval($profile_uid)
				);
				if(! count($r))
					continue;
 

				$r = q("UPDATE `photo` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s'
					WHERE `resource-id` = '%s' AND `uid` = %d AND `album` = '%s' ",
					dbesc($str_contact_allow),
					dbesc($str_group_allow),
					dbesc($str_contact_deny),
					dbesc($str_group_deny),
					dbesc($image_uri),
					intval($profile_uid),
					dbesc( t('Wall Photos'))
				);
 
			}
		}
	}


	/**
	 * Next link in any attachment references we find in the post.
	 */

	$match = false;

	if((! $preview) && preg_match_all("/\[attachment\](.*?)\[\/attachment\]/",$body,$match)) {
		$attaches = $match[1];
		if(count($attaches)) {
			foreach($attaches as $attach) {
				$r = q("SELECT * FROM `attach` WHERE `uid` = %d AND `id` = %d LIMIT 1",
					intval($profile_uid),
					intval($attach)
				);				
				if(count($r)) {
					$r = q("UPDATE `attach` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s'
						WHERE `uid` = %d AND `id` = %d LIMIT 1",
						dbesc($str_contact_allow),
						dbesc($str_group_allow),
						dbesc($str_contact_deny),
						dbesc($str_group_deny),
						intval($profile_uid),
						intval($attach)
					);
				}
			}
		}
	}

	// embedded bookmark in post? set bookmark flag

	$bookmark = 0;
	if(preg_match_all("/\[bookmark\=([^\]]*)\](.*?)\[\/bookmark\]/ism",$body,$match,PREG_SET_ORDER)) {
		$bookmark = 1;
	}

	$body = bb_translate_video($body);

	/**
	 * Fold multi-line [code] sequences
	 */

	$body = preg_replace('/\[\/code\]\s*\[code\]/ism',"\n",$body); 

	/**
	 * Look for any tags and linkify them
	 */

	$str_tags = '';
	$inform   = '';


	$tags = get_tags($body);

	/**
	 * add a statusnet style reply tag if the original post was from there
	 * and we are replying, and there isn't one already
	 */

	if(($parent_contact) && ($parent_contact['network'] === NETWORK_OSTATUS) 
		&& ($parent_contact['nick']) && (! in_array('@' . $parent_contact['nick'],$tags))) {
		$body = '@' . $parent_contact['nick'] . ' ' . $body;
		$tags[] = '@' . $parent_contact['nick'];
	}		

	if(count($tags)) {
		foreach($tags as $tag) {
			
			if(isset($profile))
				unset($profile);
			if(strpos($tag,'#') === 0) {
				if(strpos($tag,'[url='))
					continue;
				$basetag = str_replace('_',' ',substr($tag,1));
				$body = str_replace($tag,'#[url=' . $a->get_baseurl() . '/search?search=' . rawurlencode($basetag) . ']' . $basetag . '[/url]',$body);

				$newtag = '#[url=' . $a->get_baseurl() . '/search?search=' . rawurlencode($basetag) . ']' . $basetag . '[/url]';
				if(! stristr($str_tags,$newtag)) {
					if(strlen($str_tags))
						$str_tags .= ',';
					$str_tags .= $newtag;
				} 
				continue;
			}
			if(strpos($tag,'@') === 0) {
				if(strpos($tag,'[url='))
					continue;
				$stat = false;
				$name = substr($tag,1);
				if((strpos($name,'@')) || (strpos($name,'http://'))) {
					$newname = $name;
					$links = @lrdd($name);
					if(count($links)) {
						foreach($links as $link) {
							if($link['@attributes']['rel'] === 'http://webfinger.net/rel/profile-page')
                    			$profile = $link['@attributes']['href'];
							if($link['@attributes']['rel'] === 'salmon') {
								if(strlen($inform))
									$inform .= ',';
                    			$inform .= 'url:' . str_replace(',','%2c',$link['@attributes']['href']);
							}
						}
					}
				}
				else {
					$newname = $name;
					$alias = '';
					$tagcid = 0;
					if(strrpos($newname,'+')) {
						$tagcid = intval(substr($newname,strrpos($newname,'+') + 1));
						if(strpos($name,' '))
							$name = substr($name,0,strpos($name,' '));
					}	
					if($tagcid) {
						$r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d LIMIT 1",
							intval($tagcid),
							intval($profile_uid)
						);
					}
					elseif(strstr($name,'_') || strstr($name,' ')) {
						$newname = str_replace('_',' ',$name);
						$r = q("SELECT * FROM `contact` WHERE `name` = '%s' AND `uid` = %d LIMIT 1",
							dbesc($newname),
							intval($profile_uid)
						);
					}
					else {
						$r = q("SELECT * FROM `contact` WHERE `attag` = '%s' OR `nick` = '%s' AND `uid` = %d ORDER BY `attag` DESC LIMIT 1",
							dbesc($name),
							dbesc($name),
							intval($profile_uid)
						);
					}
					if(count($r)) {
						$profile = $r[0]['url'];
						if($r[0]['network'] === 'stat') {
							$newname = $r[0]['nick'];
							$stat = true;
							if($r[0]['alias'])
								$alias = $r[0]['alias'];
						}
						else
							$newname = $r[0]['name'];
						if(strlen($inform))
							$inform .= ',';
						$inform .= 'cid:' . $r[0]['id'];
					}
				}
				if($profile) {
					$body = str_replace('@' . $name, '@' . '[url=' . $profile . ']' . $newname	. '[/url]', $body);
					$profile = str_replace(',','%2c',$profile);
					$newtag = '@[url=' . $profile . ']' . $newname	. '[/url]';
					if(! stristr($str_tags,$newtag)) {
						if(strlen($str_tags))
							$str_tags .= ',';
						$str_tags .= $newtag;
					}

					// Status.Net seems to require the numeric ID URL in a mention if the person isn't 
					// subscribed to you. But the nickname URL is OK if they are. Grrr. We'll tag both. 

					if(strlen($alias)) {
						$newtag = '@[url=' . $alias . ']' . $newname	. '[/url]';
						if(! stristr($str_tags,$newtag)) {
							if(strlen($str_tags))
								$str_tags .= ',';
							$str_tags .= $newtag;
						}
					}
				}
			}
		}
	}

	$attachments = '';
	$match = false;

	if(preg_match_all('/(\[attachment\]([0-9]+)\[\/attachment\])/',$body,$match)) {
		foreach($match[2] as $mtch) {
			$r = q("SELECT `id`,`filename`,`filesize`,`filetype` FROM `attach` WHERE `uid` = %d AND `id` = %d LIMIT 1",
				intval($profile_uid),
				intval($mtch)
			);
			if(count($r)) {
				if(strlen($attachments))
					$attachments .= ',';
				$attachments .= '[attach]href="' . $a->get_baseurl() . '/attach/' . $r[0]['id'] . '" length="' . $r[0]['filesize'] . '" type="' . $r[0]['filetype'] . '" title="' . (($r[0]['filename']) ? $r[0]['filename'] : '') . '"[/attach]'; 
			}
			$body = str_replace($match[1],'',$body);
		}
	}

	$wall = 0;

	if($post_type === 'wall' || $post_type === 'wall-comment')
		$wall = 1;

	if(! strlen($verb))
		$verb = ACTIVITY_POST ;

	$gravity = (($parent) ? 6 : 0 );

	// even if the post arrived via API we are considering that it 
	// originated on this site by default for determining relayability.

	$origin = ((x($_REQUEST,'origin')) ? intval($_REQUEST['origin']) : 1);
	
	$notify_type = (($parent) ? 'comment-new' : 'wall-new' );

	$uri = item_new_uri($a->get_hostname(),$profile_uid);

	$datarray = array();
	$datarray['uid']           = $profile_uid;
	$datarray['type']          = $post_type;
	$datarray['wall']          = $wall;
	$datarray['gravity']       = $gravity;
	$datarray['contact-id']    = $contact_id;
	$datarray['owner-name']    = $contact_record['name'];
	$datarray['owner-link']    = $contact_record['url'];
	$datarray['owner-avatar']  = $contact_record['thumb'];
	$datarray['author-name']   = $author['name'];
	$datarray['author-link']   = $author['url'];
	$datarray['author-avatar'] = $author['thumb'];
	$datarray['created']       = datetime_convert();
	$datarray['edited']        = datetime_convert();
	$datarray['commented']     = datetime_convert();
	$datarray['received']      = datetime_convert();
	$datarray['changed']       = datetime_convert();
	$datarray['uri']           = $uri;
	$datarray['title']         = $title;
	$datarray['body']          = $body;
	$datarray['app']           = $app;
	$datarray['location']      = $location;
	$datarray['coord']         = $coord;
	$datarray['tag']           = $str_tags;
	$datarray['inform']        = $inform;
	$datarray['verb']          = $verb;
	$datarray['allow_cid']     = $str_contact_allow;
	$datarray['allow_gid']     = $str_group_allow;
	$datarray['deny_cid']      = $str_contact_deny;
	$datarray['deny_gid']      = $str_group_deny;
	$datarray['private']       = $private;
	$datarray['pubmail']       = $pubmail_enable;
	$datarray['attach']        = $attachments;
	$datarray['bookmark']      = intval($bookmark);
	$datarray['thr-parent']    = $thr_parent;
	$datarray['postopts']      = '';
	$datarray['origin']        = $origin;

	/**
	 * These fields are for the convenience of plugins...
	 * 'self' if true indicates the owner is posting on their own wall
	 * If parent is 0 it is a top-level post.
	 */

	$datarray['parent']        = $parent;
	$datarray['self']          = $self;
//	$datarray['prvnets']       = $user['prvnets'];

	if($orig_post)
		$datarray['edit']      = true;
	else
		$datarray['guid']      = get_guid();

	// preview mode - prepare the body for display and send it via json

	if($preview) {
		require_once('include/conversation.php');
		$o = conversation(&$a,array(array_merge($contact_record,$datarray)),'search',false,true);
		echo json_encode(array('preview' => $o));
		killme();
	}


	call_hooks('post_local',$datarray);


	if($orig_post) {
		$r = q("UPDATE `item` SET `body` = '%s', `edited` = '%s' WHERE `id` = %d AND `uid` = %d LIMIT 1",
			dbesc($body),
			dbesc(datetime_convert()),
			intval($post_id),
			intval($profile_uid)
		);

		proc_run('php', "include/notifier.php", 'edit_post', "$post_id");
		if((x($_POST,'return')) && strlen($return_path)) {
			logger('return: ' . $return_path);
			goaway($a->get_baseurl() . "/" . $return_path );
		}
		killme();
	}
	else
		$post_id = 0;


	$r = q("INSERT INTO `item` (`guid`, `uid`,`type`,`wall`,`gravity`,`contact-id`,`owner-name`,`owner-link`,`owner-avatar`, 
		`author-name`, `author-link`, `author-avatar`, `created`, `edited`, `commented`, `received`, `changed`, `uri`, `thr-parent`, `title`, `body`, `app`, `location`, `coord`, 
		`tag`, `inform`, `verb`, `postopts`, `allow_cid`, `allow_gid`, `deny_cid`, `deny_gid`, `private`, `pubmail`, `attach`, `bookmark`,`origin` )
		VALUES( '%s', %d, '%s', %d, %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, '%s', %d, %d )",
		dbesc($datarray['guid']),
		intval($datarray['uid']),
		dbesc($datarray['type']),
		intval($datarray['wall']),
		intval($datarray['gravity']),
		intval($datarray['contact-id']),
		dbesc($datarray['owner-name']),
		dbesc($datarray['owner-link']),
		dbesc($datarray['owner-avatar']),
		dbesc($datarray['author-name']),
		dbesc($datarray['author-link']),
		dbesc($datarray['author-avatar']),
		dbesc($datarray['created']),
		dbesc($datarray['edited']),
		dbesc($datarray['commented']),
		dbesc($datarray['received']),
		dbesc($datarray['changed']),
		dbesc($datarray['uri']),
		dbesc($datarray['thr-parent']),
		dbesc($datarray['title']),
		dbesc($datarray['body']),
		dbesc($datarray['app']),
		dbesc($datarray['location']),
		dbesc($datarray['coord']),
		dbesc($datarray['tag']),
		dbesc($datarray['inform']),
		dbesc($datarray['verb']),
		dbesc($datarray['postopts']),
		dbesc($datarray['allow_cid']),
		dbesc($datarray['allow_gid']),
		dbesc($datarray['deny_cid']),
		dbesc($datarray['deny_gid']),
		intval($datarray['private']),
		intval($datarray['pubmail']),
		dbesc($datarray['attach']),
		intval($datarray['bookmark']),
		intval($datarray['origin'])
	);

	$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' LIMIT 1",
		dbesc($datarray['uri']));
	if(count($r)) {
		$post_id = $r[0]['id'];
		logger('mod_item: saved item ' . $post_id);

		if($parent) {

			// This item is the last leaf and gets the comment box, clear any ancestors
			$r = q("UPDATE `item` SET `last-child` = 0, `changed` = '%s' WHERE `parent` = %d ",
				dbesc(datetime_convert()),
				intval($parent)
			);

			// Inherit ACL's from the parent item.

			$r = q("UPDATE `item` SET `allow_cid` = '%s', `allow_gid` = '%s', `deny_cid` = '%s', `deny_gid` = '%s', `private` = %d
				WHERE `id` = %d LIMIT 1",
				dbesc($parent_item['allow_cid']),
				dbesc($parent_item['allow_gid']),
				dbesc($parent_item['deny_cid']),
				dbesc($parent_item['deny_gid']),
				intval($parent_item['private']),
				intval($post_id)
			);

			if($contact_record != $author) {
				notification(array(
					'type'         => NOTIFY_COMMENT,
					'notify_flags' => $user['notify-flags'],
					'language'     => $user['language'],
					'to_name'      => $user['username'],
					'to_email'     => $user['email'],
					'item'         => $datarray,
					'link'		   => $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id,
					'source_name'  => $datarray['author-name'],
					'source_link'  => $datarray['author-link'],
					'source_photo' => $datarray['author-avatar'],
					'verb'         => ACTIVITY_POST,
					'otype'        => 'item'
				));
			
			}

			// We won't be able to sign Diaspora comments for authenticated visitors - we don't have their private key

			if($self) {
				require_once('include/bb2diaspora.php');
				$signed_body = html_entity_decode(bb2diaspora($datarray['body']));
				$myaddr = $a->user['nickname'] . '@' . substr($a->get_baseurl(), strpos($a->get_baseurl(),'://') + 3);
				if($datarray['verb'] === ACTIVITY_LIKE) 
					$signed_text = $datarray['guid'] . ';' . 'Post' . ';' . $parent_item['guid'] . ';' . 'true' . ';' . $myaddr;
				else
			    	$signed_text = $datarray['guid'] . ';' . $parent_item['guid'] . ';' . $signed_body . ';' . $myaddr;

				$authorsig = base64_encode(rsa_sign($signed_text,$a->user['prvkey'],'sha256'));

				q("insert into sign (`iid`,`signed_text`,`signature`,`signer`) values (%d,'%s','%s','%s') ",
					intval($post_id),
            		dbesc($signed_text),
            		dbesc(base64_encode($authorsig)),
            		dbesc($myaddr)
        		);
			}
		}
		else {
			$parent = $post_id;

			if($contact_record != $author) {
				notification(array(
					'type'         => NOTIFY_WALL,
					'notify_flags' => $user['notify-flags'],
					'language'     => $user['language'],
					'to_name'      => $user['username'],
					'to_email'     => $user['email'],
					'item'         => $datarray,
					'link'		   => $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id,
					'source_name'  => $datarray['author-name'],
					'source_link'  => $datarray['author-link'],
					'source_photo' => $datarray['author-avatar'],
					'verb'         => ACTIVITY_POST,
					'otype'        => 'item'
				));
			}
		}

		// fallback so that parent always gets set to non-zero.

		if(! $parent)
			$parent = $post_id;

		$r = q("UPDATE `item` SET `parent` = %d, `parent-uri` = '%s', `plink` = '%s', `changed` = '%s', `last-child` = 1, `visible` = 1
			WHERE `id` = %d LIMIT 1",
			intval($parent),
			dbesc(($parent == $post_id) ? $uri : $parent_item['uri']),
			dbesc($a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id),
			dbesc(datetime_convert()),
			intval($post_id)
		);

		// photo comments turn the corresponding item visible to the profile wall
		// This way we don't see every picture in your new photo album posted to your wall at once.
		// They will show up as people comment on them.

		if(! $parent_item['visible']) {
			$r = q("UPDATE `item` SET `visible` = 1 WHERE `id` = %d LIMIT 1",
				intval($parent_item['id'])
			);
		}
	}
	else {
		logger('mod_item: unable to retrieve post that was just stored.');
		notify( t('System error. Post not saved.'));
		goaway($a->get_baseurl() . "/" . $return_path );
		// NOTREACHED
	}

	// update the commented timestamp on the parent

	q("UPDATE `item` set `commented` = '%s', `changed` = '%s' WHERE `id` = %d LIMIT 1",
		dbesc(datetime_convert()),
		dbesc(datetime_convert()),
		intval($parent)
	);

	$datarray['id']    = $post_id;
	$datarray['plink'] = $a->get_baseurl() . '/display/' . $user['nickname'] . '/' . $post_id;

	call_hooks('post_local_end', $datarray);

	if(strlen($emailcc) && $profile_uid == local_user()) {
		$erecips = explode(',', $emailcc);
		if(count($erecips)) {
			foreach($erecips as $recip) {
				$addr = trim($recip);
				if(! strlen($addr))
					continue;
				$disclaimer = '<hr />' . sprintf( t('This message was sent to you by %s, a member of the Friendica social network.'),$a->user['username']) 
					. '<br />';
				$disclaimer .= sprintf( t('You may visit them online at %s'), $a->get_baseurl() . '/profile/' . $a->user['nickname']) . EOL;
				$disclaimer .= t('Please contact the sender by replying to this post if you do not wish to receive these messages.') . EOL; 

				$subject  = '[Friendica]' . ' ' . sprintf( t('%s posted an update.'),$a->user['username']);
				$headers  = 'From: ' . $a->user['username'] . ' <' . $a->user['email'] . '>' . "\n";
				$headers .= 'MIME-Version: 1.0' . "\n";
				$headers .= 'Content-Type: text/html; charset=UTF-8' . "\n";
				$headers .= 'Content-Transfer-Encoding: 8bit' . "\n\n";
				$link = '<a href="' . $a->get_baseurl() . '/profile/' . $a->user['nickname'] . '"><img src="' . $author['thumb'] . '" alt="' . $a->user['username'] . '" /></a><br /><br />';
				$html    = prepare_body($datarray);
				$message = '<html><body>' . $link . $html . $disclaimer . '</body></html>';
				@mail($addr, $subject, $message, $headers);
			}
		}
	}

	// This is a real juggling act on shared hosting services which kill your processes
	// e.g. dreamhost. We used to start delivery to our native delivery agents in the background
	// and then run our plugin delivery from the foreground. We're now doing plugin delivery first,
	// because as soon as you start loading up a bunch of remote delivey processes, *this* page is
	// likely to get killed off. If you end up looking at an /item URL and a blank page,
	// it's very likely the delivery got killed before all your friends could be notified.
	// Currently the only realistic fixes are to use a reliable server - which precludes shared hosting,
	// or cut back on plugins which do remote deliveries.  

	proc_run('php', "include/notifier.php", $notify_type, "$post_id");

	logger('post_complete');

	// figure out how to return, depending on from whence we came

	if($api_source)
		return;

	if($return_path) {
		goaway($a->get_baseurl() . "/" . $return_path);
	}

	$json = array('success' => 1);
	if(x($_POST,'jsreload') && strlen($_POST['jsreload']))
		$json['reload'] = $a->get_baseurl() . '/' . $_POST['jsreload'];

	logger('post_json: ' . print_r($json,true), LOGGER_DEBUG);

	echo json_encode($json);
	killme();
	// NOTREACHED
}





function item_content(&$a) {

	if((! local_user()) && (! remote_user()))
		return;

	require_once('include/security.php');

	if(($a->argc == 3) && ($a->argv[1] === 'drop') && intval($a->argv[2])) {
		require_once('include/items.php');
		drop_item($a->argv[2]);
	}
}
