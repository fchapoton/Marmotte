<?php

require_once('config.inc.php');
require_once('manage_sessions.inc.php');
require_once('generate_xml.inc.php');
require_once('authenticate_tools.inc.php');
require_once('manage_files.php');


function belongsToSection($login, $section)
{
	$all_sections = getSections($login);
	return in_array($section, $all_sections);
};

function getSection($login)
{
	$users = listUsers();
	if (isset($users[$login]))
		return $users[$login]->section_code;
	else
		throw new Exception("Unknown user '" + $login+"'");
}

function getCID($login)
{
	$users = listUsers();
	if (isset($users[$login]))
		return $users[$login]->CID_code;
	else
		throw new Exception("Unknown user '" + $login+"'");
}


function emailsACN()
{
  $users = listUsers();
  $resultat = array();
  foreach($users as $user)
    if($user->permissions == NIVEAU_PERMISSION_ACN)
      $resultat[] = $user->login;
  return $resultat;;
}

function isCurrentSectionACID()
{
	return getCID(getLogin()) == currentSection();
}

function currentSection()
{
	return $_SESSION['filter_section'];
}

function change_current_section($section)
{
	if(!belongsToSection(getLogin(), $section))
		throw new Exception("Cannot change current section, user ".$login." does not belong to section ".$section);

	$sql = "UPDATE ".users_db." SET last_section_selected='";
	$sql .= real_escape_string($section);
	$sql .= "'WHERE login='".real_escape_string(getLogin())."';";
	sql_request($sql);
	$_SESSION['filter_section'] = $section;
	unset($_SESSION["config"]);
	unset($_SESSION['all_units']);
	unset($_SESSION["all_users"]);
	unset($_SESSION["rows_id"]);
	unset($_SESSION['all_sessions']);
	unset($_SESSION['filter_id_session']);
	
	update_permissions(getLogin(), $section);
}



function get_bureau_stats()
{
	$stats = array();
	if(is_current_session_concours())
	{
		$sousjurys = getSousJuryMap();
		$concours = getConcours();

		$stats = array("Candidats CR"=>array(), "Candidats DR"=>array());
		$fields = array("rapporteur","rapporteur2","rapporteur3");

		$sql = "SELECT * FROM reports WHERE section=\"".currentSection()."\" AND id_session=\"".current_session();
		$sql .="\" AND type=\"Candidature\" AND id=id_origine AND statut!=\"supprime\"";
		$result= sql_request($sql);

		$iid_seen = array();

		while( $row = mysqli_fetch_object($result))
		{
			if(isset($concours[$row->concours]))
				$pref = substr($concours[$row->concours]->intitule,0,2);
			else
				$pref = $row->concours;
			$iid = $row->nom.$row->prenom;

			if($row->avis == "nonauditionne")
				continue;

			foreach($fields as $field)
			{
				if($row->$field != "" && !isset($stats[$pref][$row->$field][$field][$iid]))
				{
					$key = "Candidats ".$pref;
					if(!isset($stats[$key]["Total"][$field]["counter"]))
						$stats[$key]["Total"][$field]["counter"] = 0;
					if(!isset($stats[$key][$row->$field][$field][$iid]))
					{
						$stats[$key]["Total"][$field]["counter"]++;
						$stats[$key][$row->$field][$field][$iid] = "ok";
						if(!isset($stats[$key][$row->$field][$field]["counter"]))
							$stats[$key][$row->$field][$field]["counter"] = 0;
						$stats[$key][$row->$field][$field]["counter"]++;
					}
					//echo "add 1 to ".$iid." ".$pref." ".$row->$field." ".$field." tot ".$stats[$pref][$row->$field][$field]["counter"]."<br/>";
				}
			}

			$already_seen = isset($iid_seen[$iid]);
			$iid_seen[$iid] = true;

			if(!$already_seen && isset($sousjurys[$row->rapporteur][$row->concours]) && ($row->avis == "oral" ||  $row->avis == "nonclasse" || is_numeric($row->avis)))
			{
				$sj = $sousjurys[$row->rapporteur][$row->concours];
				$key = "Sousjury ".$sj;
				if(!isset($stats[$key]["Total"]["rapporteur"]["counter"]))
					$stats[$key]["Total"]["rapporteur"]["counter"] = 0;
				$stats[$key]["Total"]["rapporteur"]["counter"]++;
				if( !isset( $stats[$key][$row->rapporteur]["rapporteur"]["counter"] ) )
					$stats[$key][$row->rapporteur]["rapporteur"]["counter"] = 0;
				$stats[$key][$row->rapporteur]["rapporteur"]["counter"]++;
			}
		}
	}
	else
	{
		$sql = "SELECT * FROM reports WHERE section=\"".currentSection()."\" AND id_session=\"".current_session().  "\" AND id=id_origine AND statut!=\"supprime\"";
		$result= sql_request($sql);
		$fields = array("rapporteur","rapporteur2","rapporteur3");
		while( $row = mysqli_fetch_object($result))
		{
			foreach($fields as $field)
			{
				if($row->$field == "")
					continue;
				if(!isset($stats[$row->$field]))
				{
					foreach($fields as $field2)
						$stats[$row->$field][$field2] = 0;
					$stats[$row->$field]["total"] = 0;
				}
				$stats[$row->$field][$field]++;
				$stats[$row->$field]["total"]++;/* mysort*/
			}
		}
		//		foreach($stats as $key=>$value) echo $value["total"]."<br/>";
		//echo "Sorting according to total<br/>\n";
		//foreach($stats as $key=>$value) echo $key;
		function cmp( $stata, $statb )
		{
		  //
		  //		  echo $stata["total"]." ".$statb["total"]."<br/>";
		  return ($stata["total"] > $statb["total"]) ? -1 : (($stata["total"] == $statb["total"]) ? 0 : 1); 
		}
		uasort ( $stats, 'cmp' );
		//foreach($stats as $key=>$value) echo $value["total"]."<br/>";
	}
	return $stats;
}

/* Caching users list for performance */
function permissionToRole($perm)
{
	switch($perm)
	{
		case NIVEAU_PERMISSION_SUPER_UTILISATEUR: return "ADM";
		case NIVEAU_PERMISSION_PRESIDENT: return "PRE";
		case NIVEAU_PERMISSION_SECRETAIRE: return "SSC";
		case NIVEAU_PERMISSION_ACN: return "ACN";
		case NIVEAU_PERMISSION_BUREAU: return "BUR";
		default: return "";
	}
}

function listUsers($forcenew = false, $section = "")
{
	if($section == "")
		$section = currentSection();
	if($forcenew)
		unset($_SESSION['all_users']);

	if(!isset($_SESSION['all_users']))
	{
		$listusers = array();
		//$sql = "SELECT * FROM ".users_db." WHERE `section`='". real_escape_string($_SESSION['filter_section'])."' ORDER BY description ASC;";
		$sql = "SELECT * FROM ".users_db." ORDER BY description ASC;";
		$result= sql_request($sql);
		if($result ==  false)
			throw new Exception("Failed to process sql query ".$sql.": ".mysql_error());
		while ($row = mysqli_fetch_object($result))
		{
			if(isSuperUser())
			{
				$listusers[$row->login] = $row;
			}
			else if ($section == $row->section_code)
			{
				$row->permissions = roleToPermission($row->section_role_code);
				$listusers[$row->login] = $row;
			}
			else if ($section == $row->CID_code)
			{
				$row->permissions = roleToPermission($row->CID_role_code);
				$listusers[$row->login] = $row;
			}
			else
			{
				$sections = explode(";", $row->sections);
				if(in_array($section,$sections))
					$listusers[$row->login] = $row;
			}
		}
		$_SESSION['all_users'] = $listusers;
	}
	$all_users = $_SESSION['all_users'];

	return $all_users;
}
//SESSION['permi
function simpleListUsers()
{
	$users = listUsers();
	$result = array();
	foreach($users as $user => $row)
		$result[$row->login] = $row->description;
	return $result;
}

function listNomRapporteurs()
{
	$result = array();
	$result[''] = "";
	$users = listUsers();
	foreach($users as $login => $data)
		$result[$login] = $data->description;
	return $result;
}
//Vous n'avez pas le niveau de permission suffisa

function getCollege($login = "")
{
  if($login == "") $login = getLogin();
  $users = listUsers();
  if(isset($users[$login]))
    return $users[$login]->college;
  else
    return "";
}

function getUserPermissionLevel($login = "", $use_mask = true )
{
	$mask = NIVEAU_PERMISSION_INFINI;

	if($login == "") $login = getLogin();

	if ($login == "admin")
		return NIVEAU_PERMISSION_SUPER_UTILISATEUR;

	if($use_mask && isset($_SESSION["permission_mask"]))
		$mask = $_SESSION["permission_mask"];

	if ($login=="" || $login == getLogin())
	{
		$result = 0;
		if(isset($_SESSION['permission']))
			$result = $_SESSION['permission'];
		return min($mask, $result);
	}

	if(!isset($_SESSION["login"]))
		throw new Exception("User not logged in !");
	$login = $_SESSION["login"];

	$login = strtolower($login);

	$users = listUsers();
	if (isset($users[$login]))
	{
		$data = $users[$login];
		return min($mask, $data->permissions);
	}
	else
	{
		removeCredentials();
		throw new Exception("Unknown user '" + $login + "'");
	}
}

function genere_motdepasse($len=10)
{
	/*return openssl_random_pseudo_bytes($len);*/
	date_default_timezone_set("Europe/Paris");
	return substr(crypt(date("%l %u")),3,13);
}

function isSuperUser($login = "")
{
	if($login == "")
		$login = getLogin();
	return getUserPermissionLevel($login) >= NIVEAU_PERMISSION_SUPER_UTILISATEUR;
};

function isPresident($login = "", $use_mask = true)
{
	if($login == "")
		$login = getLogin();
	$level = getUserPermissionLevel($login, $use_mask);
	return ($level >= NIVEAU_PERMISSION_PRESIDENT);
};

function isSecretaire($login = "", $use_mask = true)
{
	if($login == "")
		$login = getLogin();
	$level = getUserPermissionLevel($login, $use_mask);
	return ($level >= NIVEAU_PERMISSION_ACN);
};

function isACN($login = "", $use_mask = true)
{
	if($login == "")
		$login = getLogin();
	$level = getUserPermissionLevel($login, $use_mask);
	return ($level == NIVEAU_PERMISSION_ACN);
};

function getLogin()
{
	if (isset($_SESSION["login"]))
		return strtolower($_SESSION["login"]);
	else
		return "";
}

function getSecretaire()
{
	$users = listUsers();
	foreach($users as $user)
		if($user->permissions == NIVEAU_PERMISSION_SECRETAIRE)
		return $user;
	return null;
}

function getPresident()
{
	$users = listUsers();
	foreach($users as $user)
		if($user->permissions == NIVEAU_PERMISSION_PRESIDENT)
		return $user;
	return null;
}

function isBureauUser($login = "", $use_mask = true)
{
	$level = getUserPermissionLevel($login, $use_mask);
	return ($level >= NIVEAU_PERMISSION_BUREAU);
};

function isSousJury($sousjury, $login = "")
{
	if($login == "" )
		$login = getLogin();
	$users = listUsers();
	if($sousjury != "" && $users[$login]->sousjury != "")
	{
		$test = strpos($users[$login]->sousjury, $sousjury);
		return ($test === 0 || $test != false);
	}
	else if($sousjury == "" && $users[$login]->sousjury == "")
		return true;
	else
		return false;
}

function isPresidentSousJury($concours = "", $sousjury = "")
{
	global $tous_sous_jury;
	return
	(isset($tous_sous_jury[$concours]))
	&&  (isset($tous_sous_jury[$concours][$sousjury]))
	&& isset($tous_sous_jury[$concours][$sousjury]["president"])
	&& (getLogin() === $tous_sous_jury[$concours][$sousjury]["president"]);
}

function changePwd($login,$old,$new1,$new2, $envoiparemail)
{
	$currLogin = getLogin();
	$users = listUsers();
	if (authenticateBase($login,$old) or isSecretaire())
	{
		$oldPassHash = getPassHash($login);
		if ($oldPassHash == NULL)
			$oldPassHash = "";
		$newPassHash = crypt($new1, $oldPassHash);
		$sql = "UPDATE ".users_db." SET passHash='$newPassHash' WHERE login='".real_escape_string($login)."';";
		sql_request($sql);

		if(getLogin() == $login)
			addCredentials($login,$new1);

		if($envoiparemail)
		{
			$body = "Votre mot de passe pour le site \r\n".adresse_du_site."/index.php?action=auth_marmotte\r\n a été mis à jour:\r\n";
			$body .= "\t\t\t login: '".$login."'\r\n";
			$body .= "\t\t\t motdepasse: '".$new1."'\r\n\r\n";
			$body .= "Merci d'utiliser ce mot de passe en cliquant sur le lien \"Authentification Marmotte\" (et non";
			$body .= "\"Authentification Janus\") depuis la page d'accueil.\r\n"; 
			$body .= "\r\n\r\n\t Amicalement, ".get_config("webmaster_nom").".";
			$cc = "";
			foreach($users as $user)
			{
				if($user->login == $currLogin && $currLogin != $login)
				{
					$cc = $user->email;
					break;
				}
			}
			email_handler($users[$login]->email,"Votre compte Marmotte",$body,$cc);
		}
		return true;
	}
	else
		throw new Exception("La saisie du mot de passe courant est incorrecte, veuillez réessayer.");
}

function changeUserInfos($login,$permissions, $sections, $section_code = "", $section_role = "", $CID_code = "", $CID_role = "", $college = "?")
{
	if(isSuperUser())
	{
		if($permissions >= NIVEAU_PERMISSION_SUPER_UTILISATEUR)
			$sections = "";
		$sql = "UPDATE `".users_db."`";
		$sql .= " SET ";
		$sql .= " `sections`='".real_escape_string($sections)."'";
		if($college != "?")
		  $sql .= ", `college`='".real_escape_string($college)."'";
		$sql .= ", `permissions`='".real_escape_string($permissions)."'";
		$sql .= " WHERE `login`='".real_escape_string($login)."';";
	}
	else if (isSecretaire() && $college != "?")
	  {
		$sql = "UPDATE `".users_db."` SET ";
		$sql .= "`college`='".real_escape_string($college)."'";
		$sql .= " WHERE `login`='".real_escape_string($login)."';";
	  }
	/*
	else if (isSecretaire())
	{
		$role = real_escape_string(permissionToRole($permissions));
		if(currentSection() == getSection($login))
			$sql = "UPDATE `".users_db."` SET `section_role_code`=\"".$role."\" WHERE `login`='".real_escape_string($login)."';";
		else if(currentSection() == getCID($login))
			$sql = "UPDATE `".users_db."` SET `CID_role_code`=\"".$role."\" WHERE `login`='".real_escape_string($login)."';";
		else
			$sql = "UPDATE `".users_db."` SET `permissions`=\"".roleToPermission($role)."\" WHERE `login`='".real_escape_string($login)."';";
	}
	*/
	sql_request($sql);
	unset($_SESSION['all_users']);
}

function getUserBySectionChaire($chaire)
{
		$sql = "SELECT * FROM ".users_db." WHERE `section_numchaire`='".real_escape_string($chaire)."';";
		$result= sql_request($sql);
		while($user = mysqli_fetch_object())
			return $user;
		return null;
}
function getUserByCIDChaire($chaire)
{
	$sql = "SELECT * FROM ".users_db." WHERE `CID_numchaire`='".real_escape_string($chaire)."';";
	$result= sql_request($sql);
	while($user = mysqli_fetch_object())
		return $user;
	return null;
}

function getEmailFromChaire($chaire)
{
  if($chaire == "") return NULL;
	$sql = "SELECT mailpro FROM ".dsidbname.".".dsi_users_db." WHERE `CID_numchaire`='".real_escape_string($chaire)."' OR `section_numchaire`='".real_escape_string($chaire)."';";
	$result= sql_request($sql);
	while($user = mysqli_fetch_object($result))
		return $user->mailpro;
	return null;
	
}

function getUserByLogin($login, $all_sections = false)
{
	if(!$all_sections)
	{
		$users = listUsers();
		if(array_key_exists($login, $users))
			return $users[$login];
		else 
			return null;
	}
	else
	{
		$sql = "SELECT * FROM ".users_db." WHERE `login`='".real_escape_string($login)."';";
		$result= sql_request($sql);
		while($user = mysqli_fetch_object($result))
			 return $user;
		return null;
	}
}

function existsUser($login, $all_sections = false)
{
	return (getUserByLogin($login,$all_sections) !== null);
}

function createUser(
		$login,$pwd,$desc,
		$email,
		$sections, $permissions,
		$section_code, $section_role_code,
		$CID_code, $CID_role_code,
		$envoiparemail = false)
{
	$login = strtolower($login);

	if($login == "admin")
		$sections = "0";

	if (isSecretaire())
	{
		if(existsUser($login))
			throw new Exception("Failed to create user: login '".$login."' already in use.");

		if($desc == "")
			throw new Exception("Failed to create user: empty description.");

		$section = currentSection();

		$result = get_user_object($login);
		if(($user = mysqli_fetch_object($result)) && !isSuperUser())
		{
			$sql = "UPDATE ".users_db." SET sections='".($user->sections.";".$section)."' WHERE login='".$login."';";
			sql_request($sql);
		}
		else
		{
			$section_code = "";
			$CID_code = "";
			if(!isSuperUser())
				$sections = "";
				if(isCurrentSectionACID())
					$CID_code = currentSection();
				else
					$section_code = currentSection();

			unset($_SESSION['all_users']);
			$passHash = crypt($pwd);
			$sql = "INSERT INTO ".users_db." (login,sections,permissions,section_code,CID_code,passHash,description,email,tel) VALUES ('";
			$sql .= real_escape_string($login)."','";
			$sql .= real_escape_string($sections)."','";
			$sql .= real_escape_string($permissions)."','";
			$sql .= real_escape_string($section_code)."','";
			$sql .= real_escape_string($CID_code)."','";
			$sql .= real_escape_string($passHash)."','";
			$sql .= real_escape_string($desc)."','";
			$sql .= real_escape_string($email)."','');";

			$result = sql_request($sql);

			if($envoiparemail)
			{
				$body = "Marmotte est un site web destiné à faciliter la répartition, le dépôt, l'édition et la production\r\n";
				$body .= "des rapports par les sections du comité national.\r\n";
				$body .= "\r\nLe site est accessible à l'adresse \r\n\t\t\t".adresse_du_site."\r\n";
				$body .= "\r\nUn compte Marmotte vient d'être créé pour vous:\r\n\r\n";
				$body .= "\t\t\t login: '".$login."'\r\n";
				$body .= "\t\t\t motdepasse: '".$pwd."'\r\n";
				$body .= "\r\nLors de votre première connexion vous pourrez changer votre mot de passe.\r\n";
				$body .= "\r\n\r\n\t Amicalement, ".get_config("webmaster_nom").".";

				$cc = "";
				$currLogin = getLogin();
				$users = listUsers();
				foreach($users as $user)
				{
					if($user->login == $currLogin && $currLogin != $login)
					{
						$cc = $user->email;
						break;
					}
				}
				email_handler($email,"Votre compte Marmotte",$body,$cc);
			}
		}
		unset($_SESSION['all_users']);
		return "Utilisateur ".$login." créé avec succès.";
	}
}

function deleteUser($login)
{
	/* Since a user can be shared by several sections,
	 * only superuser can definitively delete a user
	*/
	if (isSuperUser())
	{
		$sql = "DELETE FROM ".users_db." WHERE login='".real_escape_string($login)."';";
		sql_request($sql);
	}
	else if(isSecretaire())
	{
		$section = currentSection();
		$res = get_user_object($login);
		if ($user = mysqli_fetch_object($res))
		{

			if($user->section_code == $section)
				$user->section_code = "";
			if($user->CID_code == $section)
				$user->CID_code = "";
			$extra_sections = explode(";", $user->sections);
			$user->sections = "";
			foreach($extra_sections as $sec)
				if(($sec != $section) && ($sec != ""))
				$user->sections .= $sec.";";
			if($user->sections == "" && $user->section_code=="" && $user->CID_code=="")
			{
				$sql = "DELETE FROM `".users_db."` WHERE `login`=\"".real_escape_string($login)."\";";
			}
			else
			{
				$sql = "UPDATE `".users_db."` SET ";
				$sql .= "`section_code`=\"".real_escape_string($user->section_code)."\", ";
				$sql .= "`CID_code`=\"".real_escape_string($user->CID_code)."\", ";
				$sql .= "`sections`=\"$user->sections\" WHERE `login`=\"".real_escape_string($login)."\";";
			}
			sql_request($sql);
			if($login == getLogin())
				removeCredentials();
		}
	}
	unset($_SESSION['all_users']);
}

function deleteAllUsers()
{
	/* Since a user can be shared by several sections,
	 * only superuser can definitively delete a user
	*/
	if (isSuperUser())
	{
		unset($_SESSION['all_users']);
		$sql = "DELETE FROM ".users_db." WHERE NOT `login`='".real_escape_string(getLogin())."';";
		sql_request($sql);
	}
	else if(isSecretaire())
	{
		$users = listUsers();
		foreach($users as $login => $data)
				if($login != getLogin())
				deleteUser($login);
		unset($_SESSION['all_users']);
	}
}


function mergeUsers($old_login, $new_login,$email = true)
{
	$old_login = real_escape_string($old_login);
	$new_login = real_escape_string($new_login);
	$fields = array("rapporteur","rapporteur2","rapporteur3");
	foreach($fields as $field)
	{
		$sql = "UPDATE ".reports_db." SET `".$field."`='".$new_login."' WHERE `".$field."`='".$old_login."'";
		if(!isSuperUser())
			$sql .= " AND `section`='".currentSection()."'";
		$sql.=";";
		sql_request($sql);
	}
	deleteUser($old_login);
	unset($_SESSION['all_users']);

	if($email)
	  {
	$recipient = $new_login;
        $subject = "Fusion de vos comptes Marmotte ".$old_login." et ".$new_login;
	$body = "Bonjour,\r\n\r\n cet email a été envoyé automatiquement par le site ".adresse_du_site." afin de vous prévenir";
	$body .= "que vos deux comptes";
	$body .=" Marmotte ".$old_login." et ".$new_login." ont ete fusionnés.\r\n\r\n";
	$body .= "Les demandes d'évaluation attribuées à ".$old_login." ont été réattribuées à ".$new_login."\r\n\r\n.";
  $body .="Veuillez désormais vous logger dans Marmotte avec vos identifiants e-valuation (Janus) associés à votre email ".$new_login.".\r\n\r\n";
  $body .= "En cas de difficultés de connexion, veuillez contacter votre ACN.\r\n\r\n\r\n";
  $body .= "Bien cordialement,\r\n\t ".get_config("webmaster_nom")."\r\n";
try
  {
    email_handler($recipient,$subject,$body,get_config("webmaster"));
  }
catch(Exception $e)
  {
    echo "Failed to send email to ".$recipient."<br/>";
}
	  }
}

?>