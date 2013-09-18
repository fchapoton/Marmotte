<?php

require_once 'config.inc.php';
require_once 'import.inc.php';
require_once('manage_unites.inc.php');
require_once('manage_rapports.inc.php');

/*
 * Upload de fichier csv avec séparateur ; entrées encadrées par des "", encodé en utf-8
Les données d'un labo avec le même code seront remplacées.
*/

function fixEncoding($in_str)
{
	$cur_encoding = mb_detect_encoding($in_str) ;
	if($cur_encoding == "UTF-8" && mb_check_encoding($in_str,"UTF-8"))
		return $in_str;
	else
		return utf8_encode($in_str);
}

function import_csv($type,$filename, $subtype = "", $sep=";", $del="\n",$enc='"', $esc='\\')
{
	global $fieldsAll;
	global $csv_composite_fields;
	global $fieldsUnitsDB;

	$output = "";




	if($file = fopen ( $filename , 'r') )
	{
		$is_utf8 = true;
		
		$rawfields = fgetcsv ( $file, 0, $sep , $enc, $esc );
		
		if($is_utf8)
			foreach($rawfields as $field)
		 $is_utf8 = $is_utf8 && mb_check_encoding($field,"UTF-8");
		
		if(!$is_utf8)
		{
			for($i = 0 ; $i < count($rawfields); $i++)
				$rawfields[$i] = utf8_encode($rawfields[$i]);
		}
		
		$fields = array();
		foreach($rawfields as $field)
			$fields[] = mysql_real_escape_string($field);

		$with_id = in_array("id",$fields);
		$id_rank = array_search("id",$fields);

		if(!isSecretaire())
		{
			if(!$with_id)
				throw new Exception("Vous n'avez pas les permissions nécessaires pour importer des rapports au format csv sans fournir un champ préciser l'id du rapport original.");
			if($type != 'evaluations')
				throw new Exception("Vous n'avez pas les permissions nécessaires pour importer des données autres que des évaluations.");
		}


		$nbfields = count($fields);

		$nb = 0;
		$errors = "";


		while(($data = fgetcsv ( $file, 0, $sep , $enc ,$esc)) != false)
		{
			$nb++;

			if($is_utf8)
				for($i = 0 ; $i < count($data); $i++)
				$is_utf8 = $is_utf8 && mb_check_encoding($data[$i],"UTF-8");

			if(!$is_utf8)
			{
				for($i = 0 ; $i < count($data); $i++)
				$data[$i] = utf8_encode($data[$i]);
			}


			try
			{
				set_time_limit(0);
				if($type == 'evaluations')
				{
					if($with_id)
					{
						if(count($data) != $nbfields)
							$errors .= "Line ".$nb." : failed to process : wrong number of data fields ".count(data)." instead of ".$nbfields." like the first line<br/>";
						else
						{
							$id_origine = $data[$id_rank];
							$properties = array();
							for($i = 0; $i < $nbfields; $i++)
							{
								$properties[$fields[$i]] =  $data[$i];
							}
							$report = change_report_properties($id_origine, $properties);
							$output .= "Line ".$nb." : updated data of report ".$id_origine . " (new report has id ".$report->id.")<br/>";
						}
					}
					else if(in_array("type",$fields))
					{
						$i = array_search("type",$fields);
						if(isset($data[$i]))
						{
							$newsubtype = $data[$i];
							if($newsubtype != "")
								$subtype  = $newsubtype;
						}
						addCsvReport($subtype, $data, $fields);
					}
					else
					{
						for($i = 0; $i < $nbfields; $i++)
							$properties[$fields[$i]] =  $data[$i];
						addCsvReportNoType($properties);
					}
				}
				else if ($type == 'unites')
				{
					addCsvUnite($data, $fields);
				}
				else
					throw new Exception("Unknown generic csv report type \'".$type."\'");
			}
			catch(Exception $exc)
			{
				$errors .= "Line ".$nb." : failed to process : ". $exc->getMessage()."<br/>";
			}
		}
		if($errors != "")
			return $nb." rapports de type ".$type."/".$subtype." ont été ajoutés. Erreurs:\n\t".$errors."<br/> and output <br/>".$output;
		else if($output != "")
			return $nb." rapports de type ".$type."/".$subtype." ont été ajoutés <br/>".$output;
		else
			return $nb." rapports de type ".$type."/".$subtype." ont été ajoutés.";
	}
	else
	{
		throw new Exception("Failed to open file ".$filename." for reading");
	}
}

function addToReport($report, $field, $data)
{
	global $csv_composite_fields;
	global $csv_preprocessing;
	global $fieldsAll;
	global $fieldsTypes;

	if(isset($csv_composite_fields[$field]))
	{
		$subfields = $csv_composite_fields[$field];
		$pieces = explode(" ",$data, count($subfields));
		$i;
		for($i = 0; $i < count($pieces); $i++)
			addToReport($report, $subfields[$i], $pieces[$i]);
	}
	else
	{
		if(key_exists($field, $fieldsAll))
		{
			$preproc = preprocess($field, $data);
			$report->$field .= $preproc;
		}
	}
}

function preprocess($field, $data)
{
	global $csv_preprocessing;
	global $fieldsTypes;

	$result = $data;

	if(isset($csv_preprocessing[$field]))
	{
		$result = call_user_func($csv_preprocessing[$field],$data);
	}
	else if(isset($fieldsTypes[$field]))
	{
		$type = $fieldsTypes[$field];
		if(isset($csv_preprocessing[$type]))
			$result =  call_user_func($csv_preprocessing[$type],$data);
	}
	else
	{
		$result = $data;
	}
	return $result;
}


function getDocFromCsv($data, $fields)
{

	$report = (object) array();

	$m = min(count($data), count($fields));
	for($i = 0; $i < $m; $i++)
		addToReport($report,$fields[$i], replace_accents($data[$i]));

	return $report;

}

function strcontains($haystack, $needle)
{
	return (strpos($haystack,$needle) !== false);	
}

function addCsvReportNoType($properties)
{
	//Check emptiness
	$non_empty = false;
	foreach($properties as $key => $value)
		if($value != "")
		$non_empty = true;
	
	if(!$non_empty)
		return;
	
	//first we try to get the type of the evaluation
	$grade = "";
	$grade_rapport = "";
	
	if( isset( $properties["Type évaluation"] ) )
		$properties["type"] = $properties["Type évaluation"];

	if( isset( $properties["Type d\'évaluation"] ) )
		$properties["type"] = $properties["Type d\'évaluation"];
	
	if(!isset($properties["type"]) )
		throw new Exception("Cannot add csv report, no type available");

	$types_keys = array(
			"Evaluation" => 'Evaluation-Vague',
			'Reconstitution' => 'Reconstitution',
			'Titularisation' => 'Titularisation',
			'promotion' => 'Promotion',
			'Changement de direction' => 'Changement-Directeur',
			'Changement de section' => 'Changement-section',
			'Expertise' => 'Expertise',
			"Renouvellement de GDR" =>  'Renouvellement',
			"Evaluation" => "",
			);
	
	foreach($types_keys as $key => $value)
		if(strcontains($properties["type"],$key))
		{
			if($key == "promotion")
			{
				if(strcontains($properties["type"],"CR1"))
					$properties["grade_rapport"] = "CR1";
				if(strcontains($properties["type"],"DR1"))
					$properties["grade_rapport"] = "DR1";
				if(strcontains($properties["type"],"DRCE1"))
					$properties["grade_rapport"] = "DRCE1";
				if(strcontains($properties["type"],"DRCE2"))
					$properties["grade_rapport"] = "DRCE2";
				$properties["type"] = "Promotion";
				
			}
			else if($key == "Evaluation")
			{
				if(isset($properties["Phase évaluation"]) && ($properties["Phase évaluation"] =="mi-vague"))
					$properties["type"] = 'Evaluation-MiVague';
				else
					$properties["type"] = 'Evaluation-Vague';
			}
			else
				$properties["type"] = $value;
		}		
	
	if(!isset($properties["type"]) || $properties["type"] =="")	
		throw new Exception("Unimplemented report type:" . $type);

	$copies = array(
			"Nom" => "nom",
			"Prénom" => "prenom",
			"Grade" => "grade",
			"Directeur" => "directeur",
			"Affectation #1" => "unite",
			"Code Unité" => "unite",
			"Affectation #1" => "unite"
	);
			
	foreach($copies as $old => $new)
		if(isset($properties[$old]) )
	{
		$properties[$new] = $properties[$old];
		unset($properties[$old]);
	}

		
	
	if(isset($properties["unite"]))
		$properties["code"] = $properties["unite"];
	if(isset($properties["grade"]) && !isset($properties["grade_rapport"]))
		$properties["grade_rapport"] = $properties["grade"];
	
	
	$properties["rapport"] = "";
	foreach($properties as $key => $value)
		if($value != "")
		$properties["rapport"] .= $key . " : " . $value."\n\n";
	
	
	$report = (object) array();
	
	foreach($properties as $key => $value)
		addToReport($report,$key, replace_accents($value));

	$report->statut = 'vierge';
	$report->id_session = current_session_id();
	
	global $typesRapportsChercheurs;
	global $typesRapportsConcours;
	
	if( in_array($report->type, $typesRapportsChercheurs) || in_array($report->type, $typesRapportsConcours) )
		updateCandidateFromData((object) $properties);
	
	addReport($report,false);
	
	if(isset($report->unite))
		updateUnitData($report->unite, (object) $report);
	
}



function addCsvReport($type, $data, $fields)
{

	if(isset($data["code"]))
		$data["unite"] = $data["code"];
	if(isset($data["unite"]))
		$data["code"] = $data["unite"];

	$non_empty = false;
	foreach($data as $d)
		if($d != "")
		$non_empty = true;

	if(!$non_empty)
		return;

	$report = getDocFromCsv($data,$fields);
	$report->statut = 'vierge';

	if(isset($report->type) && $report->type != "")
	{
		$type = $report->type;
	}
	else if($type != "")
	{
		$report->type = $type;
	}
	else
	{
		echo "Skipping report</br>";
		return;
	}

	$report->id_session = current_session_id();

	global $typesRapportsChercheurs;
	global $typesRapportsConcours;

	if( in_array($report->type, $typesRapportsChercheurs) || in_array($report->type, $typesRapportsConcours) )
		updateCandidateFromData((object) $data);

	addReport($report,false);

	if(isset($data->unite))
		updateUnitData($data->unite, (object) $data);
}

function addCsvUnite($data, $fields)
{
	addUnit($data[1], $data[0], $data[2], $data[3]);
}



?>