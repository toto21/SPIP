<?php

/***************************************************************************\
 *  SPIP, Systeme de publication pour l'internet                           *
 *                                                                         *
 *  Copyright (c) 2001-2012                                                *
 *  Arnaud Martin, Antoine Pitrou, Philippe Riviere, Emmanuel Saint-James  *
 *                                                                         *
 *  Ce programme est un logiciel libre distribue sous licence GNU/GPL.     *
 *  Pour plus de details voir le fichier COPYING.txt ou l'aide en ligne.   *
\***************************************************************************/

if (!defined("_ECRIRE_INC_VERSION")) return;

//
// Fonctions d'appel aux serveurs SQL presentes dans le code compile
//

# NB : a l'exception des fonctions pour les balises dynamiques

include_spip('base/abstract_sql');

# retourne le chapeau d'un article, et seulement s'il est publie

// http://doc.spip.org/@quete_chapo
function quete_chapo($id_article, $connect) {
	return sql_getfetsel('chapo', 'spip_articles', array("id_article=".intval($id_article), "statut='publie'"), '','','','',$connect);
}

function quete_parent_lang($table,$id,$connect=''){
	static $cache_quete = array();
	
	if (!isset($cache_quete[$connect][$table][$id])
	AND in_array($table,array('spip_rubriques','spip_articles','spip_syndic','spip_breves'))){
		$select = ($table=='spip_rubriques'?'id_parent':'id_rubrique');
		$select .= in_array($table,array('spip_rubriques','spip_articles','spip_breves'))?", lang":"";
		$_id = id_table_objet(objet_type($table));
		$cache_quete[$connect][$table][$id] = sql_fetsel($select, $table,"$_id=".intval($id),'','','','',$connect);
	}
	return $cache_quete[$connect][$table][$id];
}


# retourne le parent d'une rubrique
// http://doc.spip.org/@quete_parent
function quete_parent($id_rubrique, $connect='') {
	if (!$id_rubrique = intval($id_rubrique))
		return 0;
	$id_parent = quete_parent_lang('spip_rubriques',$id_rubrique,$connect);
	return $id_parent['id_parent'];
}

# retourne la rubrique d'un article

// http://doc.spip.org/@quete_rubrique
function quete_rubrique($id_article, $serveur) {
	$id_parent = quete_parent_lang('spip_articles',$id_article,$serveur);
	return $id_parent['id_rubrique'];
}


# retourne la profondeur d'une rubrique

// http://doc.spip.org/@quete_profondeur
function quete_profondeur($id, $connect='') {
	$n = 0;
	while ($id) {
		$n++;
		$id = quete_parent($id, $connect);
	}
	return $n;
}


# retourne la date a laquelle comparer lorsqu'il y a des post-dates
// http://doc.spip.org/@quete_date_postdates
function quete_date_postdates() {
	return
		($GLOBALS['meta']['date_prochain_postdate'] > time())
			? date('Y-m-d H:i:s', $GLOBALS['meta']['date_prochain_postdate'])
			: '9999-12-31';
}


# retourne le fichier d'un document

// http://doc.spip.org/@quete_fichier
function quete_fichier($id_document, $serveur) {
	return sql_getfetsel('fichier', 'spip_documents', ("id_document=" . intval($id_document)),	'',array(), '', '', $serveur);
}

// http://doc.spip.org/@quete_petitions
function quete_petitions($id_article, $table, $id_boucle, $serveur, &$cache) {
	$retour = sql_getfetsel('texte', 'spip_petitions',("id_article=".intval($id_article)),'',array(),'','', $serveur);

	if ($retour === NULL) return '';
	# cette page est invalidee par toute petition
	$cache['varia']['pet'.$id_article] = 1;
	# ne pas retourner '' car le texte sert aussi de presence
	return $retour ? $retour : ' ';
}

# retourne le champ 'accepter_forum' d'un article
// http://doc.spip.org/@quete_accepter_forum
function quete_accepter_forum($id_article) {
	// si la fonction est appelee en dehors d'une boucle
	// article (forum de breves), $id_article est nul
	// mais il faut neanmoins accepter l'affichage du forum
	// d'ou le 0=>'' (et pas 0=>'non').
	static $cache = array(0 => '');
	
	$id_article = intval($id_article);

	if (isset($cache[$id_article]))	return $cache[$id_article];

	return $cache[$id_article] = sql_getfetsel('accepter_forum','spip_articles',"id_article=$id_article");
}

// recuperer une meta sur un site distant (en local il y a plus simple)
// http://doc.spip.org/@quete_meta
function quete_meta($nom, $serveur) {
	return sql_getfetsel("valeur", "spip_meta", "nom=" . sql_quote($nom),
			     '','','','',$serveur);
}


// http://doc.spip.org/@calcul_exposer
function calcul_exposer ($id, $prim, $reference, $parent, $type, $connect='') {
	static $exposer = array();
	static $ref_precedente =-1;

	// Que faut-il exposer ? Tous les elements de $reference
	// ainsi que leur hierarchie ; on ne fait donc ce calcul
	// qu'une fois (par squelette) et on conserve le resultat
	// en static.
	if (!isset($exposer[$m=md5(serialize($reference))][$prim])) {
		$principal = isset($reference[$type])?$reference[$type]:
			// cas de la pagination indecte @xx qui positionne la page avec l'id xx
			// et donne la reference dynamique @type=xx dans le contexte
			(isset($reference["@$type"])?$reference["@$type"]:'');
		// le parent fournit en argument est le parent de $id, pas celui de $principal
		// il n'est donc pas utile
		$parent = 0;
		if (!$principal) { // regarder si un enfant est dans le contexte, auquel cas il expose peut etre le parent courant
			$enfants = array('id_rubrique'=>array('id_article'),'id_groupe'=>array('id_mot'));
			if (isset($enfants[$type]))
				foreach($enfants[$type] as $t)
					if (isset($reference[$t])
						// cas de la reference donnee dynamiquement par la pagination
						OR isset($reference["@$t"])) {
						$type = $t;
						$principal = isset($reference[$type])?$reference[$type]:$reference["@$type"];
						continue;
					}
		}
		$exposer[$m][$type] = array();
		if ($principal) {
			$principaux = is_array($principal)?$principal:array($principal);
			foreach($principaux as $principal){
				$exposer[$m][$type][$principal] = true;
				if ($type == 'id_mot'){
					if (!$parent) {
						$parent = sql_fetsel('id_groupe','spip_mots',"id_mot=" . $principal, '','','','',$connect);
						$parent = $parent['id_groupe'];
					}
					if ($parent)
						$exposer[$m]['id_groupe'][$parent] = true;
				}
				else if ($type != 'id_groupe') {
				  if (!$parent) {
				  	if ($type == 'id_rubrique')
				  		$parent = $principal;
				  	if ($type == 'id_article') {
							$parent = sql_fetsel('id_rubrique','spip_articles',"id_article=" . $principal, '','','','',$connect);
							$parent = $parent['id_rubrique'];
				  	}
				  }
				  do { $exposer[$m]['id_rubrique'][$parent] = true; }
				  while ($parent = quete_parent($parent, $connect));
				}
			}
		}
	}
	// And the winner is...
	return isset($exposer[$m][$prim]) ? isset($exposer[$m][$prim][$id]) : '';
}

// fonction appelee par la balise #LOGO_DOCUMENT
// http://doc.spip.org/@calcule_logo_document
function calcule_logo_document($id_document, $doubdoc, &$doublons, $flag_fichier, $lien, $align, $params='', $connect='') {
	include_spip('inc/documents');

	if (!$id_document) return '';
	if ($doubdoc) $doublons["documents"] .= ','.$id_document;

	if (!($row = sql_fetsel('titre, taille, extension, id_vignette, fichier, mode', 'spip_documents', ("id_document = $id_document"),'','','','',$connect))) {
		// pas de document. Ne devrait pas arriver
		spip_log("Erreur du compilateur doc $id_document inconnu");
		return ''; 
	}

	$extension = $row['extension'];
	$id_vignette = $row['id_vignette'];
	$fichier = $row['fichier'];
	$mode = $row['mode'];

	// taille maximum [(#LOGO_DOCUMENT{300,52})]
	// et/ou type de la vignette [(#LOGO_DOCUMENT{icone,300,52})]
	// parmi :
	// icone -> icone du type de document
	// apercu -> image reduite si possible (mais pas la vignette telechargee) ou logo_type sinon
	// vignette -> vignette telechargee uniquement, sinon rien
	// auto -> mode par defaut si pas indique : vignette, sinon image reduite, sinon logo_type

	$mode_logo = 'auto';
	$params = explode(',',rtrim(ltrim($params,'{'),'}'));
	$params = array_map('trim',$params);
	if (in_array(reset($params),array('icone','apercu','auto','vignette')))
		$mode_logo = array_shift($params);
	// ignorer un mode errone
	elseif (count($params)==3 AND !is_numeric($params[0]))
		array_shift($params);
	if (is_numeric($params[0]) AND is_numeric($params[1])){
		$x = intval($params[0]);
		$y = intval($params[1]);
	}
	else $x = $y = 0;

	if ($mode_logo == 'vignette' AND !$id_vignette)
		return '';

	if ($mode_logo == 'icone')
		$logo = img_logo_document($fichier, $extension, 0, 'icone', $x, $y, $connect);
	elseif ($mode_logo == 'apercu')
		$logo = img_logo_document($fichier, $extension, 0, $mode, $x, $y, $connect);
	else
		$logo = img_logo_document($fichier, $extension, $id_vignette, $mode, $x, $y, $connect);

	// flag_fichier : seul le fichier est demande
	if ($flag_fichier)
		return set_spip_doc(extraire_attribut($logo, 'src'));

	// Calculer le code html complet (cf. calcule_logo)
	$logo = inserer_attribut($logo, 'alt', '');
	$logo = inserer_attribut($logo, 'class', 'spip_logos');
	if ($align) $logo = inserer_attribut($logo, 'align', $align);
	if (!$lien) return $logo;
	$titre = supprimer_tags(typo($row['titre']));
	$taille = taille_en_octets($row['taille']);

	$type = sql_fetsel('titre, mime_type','spip_types_documents', "extension = " . sql_quote($extension));

	$mime = $type['mime_type'];
	$titre = $type['titre'] . " - $taille" . ($titre ? " - $titre" : "");
	$titre = attribut_html(couper($titre, 80));
	return "<a href='$lien' type='$mime' title='$titre'>$logo</a>";
}

function img_logo_document($fichier, $extension, $id_vignette, $mode, $x, $y, $connect='')
{
	if ($id_vignette) {
		$vignette = quete_fichier($id_vignette, $connect);
		if ($connect) {
			$site = quete_meta('adresse_site', $connect);
			$dir = quete_meta('dir_img', $connect);
			$logo = "$site/$dir$vignette";
		}
		elseif (@file_exists(get_spip_doc($vignette)))
			$logo = generer_url_entite($id_vignette, 'document');
	}
	else if ($mode == 'vignette') {
		$logo = generer_url_entite($id_vignette, 'document');
		if (!@file_exists($logo)) $logo = '';
	} else $logo = '';


	if ($logo AND @file_exists($logo)) {
		if (!$x AND !$y) {
			$size = @getimagesize($logo);
			$logo = "<img src='$logo' ".$size[3]." />";
		}
	}
	else {
		// Pas de vignette, mais un fichier image -- creer la vignette
		// sauf si icone demandee explicitement
		if (strpos($GLOBALS['meta']['formats_graphiques'], $extension)!==false
		AND (!test_espace_prive() OR $GLOBALS['meta']['creer_preview']=='oui')
	  AND $mode!=='icone') {
		  if ($img = _DIR_RACINE.copie_locale(get_spip_doc($fichier))
			AND @file_exists($img)) {
				if (!$x AND !$y) {
					$logo = reduire_image($img);
				} else {
					# eviter une double reduction
					$size = @getimagesize($img);
					$logo = "<img src='$img' ".$size[3]." />";
				}
		  }
		  // cas de la vignette derriere un htaccess
		} elseif ($logo) $logo = "<img src='$logo' />";

		// Document sans vignette ni image : vignette par defaut
		if (!$logo) {
			$img = vignette_par_defaut($extension, false);
			$size = @getimagesize($img);
			$logo = "<img src='$img' ".$size[3]." />";
		}
	}

	// Reduire si une taille precise est demandee
	return ($x OR $y) ? reduire_image($logo, $x, $y) : $logo;
}
// Ajouter "&lang=..." si la langue du forum n'est pas celle du site.
// Si le 2e parametre n'est pas une chaine, c'est qu'on n'a pas pu
// determiner la table a la compil, on le fait maintenant.
// Il faudrait encore completer: on ne connait pas la langue
// pour une boucle forum sans id_article ou id_rubrique issu du contexte,
// ce qui provoque un Log abscons ("table inconnue forum")
// voire une erreur SQL dans le cas de id_syndic, qu'on neutralise 
// in extremis mais ce n'est pas satisfaisant
// http://doc.spip.org/@lang_parametres_forum
function lang_parametres_forum($qs, $lang) {
	if (is_array($lang) AND preg_match(',id_(\w+)=([0-9]+),', $qs, $r)) {
		$id = 'id_' . $r[1];
		if ($t = $lang[$id] AND $id != 'id_syndic')
			$lang = sql_getfetsel('lang', $t, "$id=" . $r[2]);
		else $lang = '';
	}
  // Si ce n'est pas la meme que celle du site, l'ajouter aux parametres

	if ($lang AND $lang <> $GLOBALS['meta']['langue_site'])
		return $qs . "&lang=" . $lang;

	return $qs;
}

/**
 * Trouver la page d'une liste qui contient l'id_primaire
 * indiquee par la pagination par indirection debut_xxx=@xxxx
 *
 * @param string $primary
 * @param string $valeur
 * @param int $pas
 * @param resource $res
 * @param string $serveur
 * @return int
 */
function quete_debut_pagination($primary,$valeur,$pas,$res,$serveur=''){
	// on ne devrait pas arriver ici si la cle primaire est inexistante
	// ou composee, mais verifions
	if (!$primary OR preg_match('/[,\s]/',$primary))
		return 0;

	$pos = 0;
	while ($row = sql_fetch($res,$serveur) AND $row[$primary]!=$valeur){
		$pos++;
	}
	// si on a pas trouve
	if ($row[$primary]!=$valeur)
		return 0;

	// sinon, calculer le bon numero de page
	return $pas?(floor($pos/$pas)*$pas):0; // en mode debug, il semble qu'on passe ici avec un mauvais pas
}

?>