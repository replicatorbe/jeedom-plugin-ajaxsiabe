<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('ajaxsiabe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());

$hubs = array();
$zonesByHub = array();
foreach ($eqLogics as $eqLogic) {
	if ($eqLogic->getConfiguration('type') == ajaxsiabe::TYPE_ZONE) {
		$zonesByHub[(int) $eqLogic->getConfiguration('hub_id')][] = $eqLogic;
	} else {
		$hubs[] = $eqLogic;
	}
}
/* Appareils du plugin ajaxSystem, pour lier une zone à son appareil du cloud.
 * Rendus dans la page : la liste est courte et ne change qu'à la
 * synchronisation d'ajaxSystem. */
$cloudDevices = ajaxsiabe::cloudDevices();
/* La table de correspondance du cloud par défaut, pour la préremplir. */
sendVarToJS('ajaxsiabeDefaultCloudMap', ajaxsiabePilot::DEFAULT_CLOUD_MAP);
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<div class="cursor eqLogicAction logoPrimary" data-action="add">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter un hub}}</span>
			</div>
			<!-- Un lien suffit : le coeur charge en AJAX toute ancre interne. Le
			     <div> intérieur porte la mise en forme des tuiles. -->
			<a href="index.php?v=d&amp;m=ajaxsiabe&amp;p=journal" style="text-decoration:none;">
				<div class="cursor logoSecondary">
					<i class="fas fa-stream"></i>
					<br>
					<span>{{Journal SIA}}</span>
				</div>
			</a>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<div class="alert alert-info" id="div_ajaxsiabeStatus" style="margin:5px;">
			<i class="fas fa-circle-notch fa-spin"></i> {{Interrogation du récepteur…}}
		</div>

		<!--
			Assistant de raccordement : tout ce qu'il faut recopier dans Ajax PRO,
			avec un bouton de copie par valeur. Déplié tant qu'aucun hub n'existe.
			Les valeurs sont remplies par desktop/js/ajaxsiabe.js (action
			« connection ») : la clé ne transite donc jamais par le HTML de la page.
		-->
		<div class="panel panel-default" id="div_ajaxsiabeConnect" style="margin:5px;">
			<div class="panel-heading cursor" id="bt_ajaxsiabeConnectToggle" role="button" tabindex="0" aria-controls="div_ajaxsiabeConnectBody">
				<h3 class="panel-title"><i class="fas fa-plug"></i> {{Raccorder un hub}} <i class="fas fa-chevron-down pull-right"></i></h3>
			</div>
			<div class="panel-body" id="div_ajaxsiabeConnectBody" data-open="<?php echo (count($hubs) == 0) ? '1' : '0'; ?>" style="display:none;">
				<p>{{Dans l'application Ajax PRO : Hub → Paramètres → Centre de télésurveillance. Recopiez ces valeurs :}}</p>
				<table class="table table-condensed" style="max-width:720px;">
					<tbody>
						<tr><td>{{Protocole}}</td><td><b>SIA DC-09 (SIA-DCS)</b></td><td></td></tr>
						<tr><td>{{Adresse IP}}</td><td><b class="ajaxsiabeConnectValue" data-field="ip"></b> <small class="text-muted" id="span_ajaxsiabeNoIp" style="display:none;">{{à régler dans Réglages → Système → Configuration → Réseaux}}</small></td><td><a href="#" role="button" class="btn btn-xs btn-default bt_ajaxsiabeCopy" data-field="ip" title="{{Copier l'adresse IP}}" aria-label="{{Copier l'adresse IP}}"><i class="fas fa-copy" aria-hidden="true"></i></a></td></tr>
						<tr><td>{{Port}}</td><td><b class="ajaxsiabeConnectValue" data-field="port"></b> <small class="text-muted">(TCP)</small></td><td><a href="#" role="button" class="btn btn-xs btn-default bt_ajaxsiabeCopy" data-field="port" title="{{Copier le port}}" aria-label="{{Copier le port}}"><i class="fas fa-copy" aria-hidden="true"></i></a></td></tr>
						<tr><td>{{Numéro d'objet}}</td><td><b class="ajaxsiabeConnectValue" data-field="account"></b> <small class="text-muted">{{(proposé : libre, vous pouvez en choisir un autre de 3 à 16 caractères hexadécimaux)}}</small></td><td><a href="#" role="button" class="btn btn-xs btn-default bt_ajaxsiabeCopy" data-field="account" title="{{Copier le numéro d'objet}}" aria-label="{{Copier le numéro d'objet}}"><i class="fas fa-copy" aria-hidden="true"></i></a></td></tr>
						<tr>
							<td>{{Clé de chiffrement}}</td>
							<td>
								<b class="ajaxsiabeConnectValue" data-field="key" style="font-family:monospace;"></b>
								<a href="#" role="button" class="btn btn-xs btn-warning" id="bt_ajaxsiabeGenerateKey"><i class="fas fa-key"></i> <span></span></a>
							</td>
							<td><a href="#" role="button" class="btn btn-xs btn-default bt_ajaxsiabeCopy" data-field="key" title="{{Copier la clé}}" aria-label="{{Copier la clé}}"><i class="fas fa-copy" aria-hidden="true"></i></a></td>
						</tr>
						<tr><td>{{Intervalle de test (ping)}}</td><td><b>1 {{min}}</b> <small class="text-muted">{{(1 à 5 min : c'est lui qui permet de voir tomber la liaison)}}</small></td><td></td></tr>
					</tbody>
				</table>
				<p class="text-muted" style="margin:0;">{{Le hub apparaît ici dès son premier message (rechargez la page), et chaque appareil au premier événement qui le concerne. Ouvrez le Journal SIA en suivi direct pour voir arriver les messages. Si « Créer les hubs inconnus » est décoché dans la configuration, créez le hub à la main avec son numéro de compte.}}</p>
			</div>
		</div>

		<div class="input-group" style="margin:5px;">
			<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">
			<div class="input-group-btn">
				<a id="bt_resetSearch" class="btn" style="width:30px" title="{{Effacer la recherche}}" aria-label="{{Effacer la recherche}}"><i class="fas fa-times"></i></a>
				<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>
			</div>
		</div>

		<?php
		$displayCard = function ($_eqLogic, $_icon) {
			$opacity = ($_eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $_eqLogic->getId() . '">';
			echo '<i class="fas ' . $_icon . '" style="font-size:4em;"></i>';
			echo '<br>';
			echo '<span class="name">' . $_eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo ($_eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		};

		foreach ($hubs as $hub) {
			echo '<legend><i class="fas fa-shield-alt"></i> ' . htmlspecialchars($hub->getName()) . ' <small>#' . htmlspecialchars($hub->getConfiguration('account')) . '</small></legend>';
			echo '<div class="eqLogicThumbnailContainer">';
			$displayCard($hub, 'fa-shield-alt');
			$zones = isset($zonesByHub[(int) $hub->getId()]) ? $zonesByHub[(int) $hub->getId()] : array();
			usort($zones, function ($_a, $_b) {
				return (int) $_a->getConfiguration('zone') - (int) $_b->getConfiguration('zone');
			});
			foreach ($zones as $zone) {
				$displayCard($zone, 'fa-door-open');
			}
			echo '</div>';
			unset($zonesByHub[(int) $hub->getId()]);
		}
		/* Zones dont le hub a été supprimé : elles restent atteignables pour
		 * pouvoir les supprimer à leur tour. */
		if (!empty($zonesByHub)) {
			echo '<legend><i class="fas fa-question-circle"></i> {{Appareils sans hub}}</legend>';
			echo '<div class="eqLogicThumbnailContainer">';
			foreach ($zonesByHub as $zones) {
				foreach ($zones as $zone) {
					$displayCard($zone, 'fa-door-open');
				}
			}
			echo '</div>';
		}
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex;">
			<span class="input-group-btn">
				<a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span>
				</a><a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}
				</a><a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}
				</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Equipement}}</span></a></li>
			<li role="presentation" class="ajaxsiabeHubBlock"><a href="#cloudtab" aria-controls="cloudtab" role="tab" data-toggle="tab"><i class="fas fa-cloud"></i><span class="hidden-xs"> {{Pilotage cloud}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<form class="form-horizontal">
					<fieldset>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
							<div class="col-sm-6">
								<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
								<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom}}">
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Objet parent}}</label>
							<div class="col-sm-6">
								<select class="eqLogicAttr form-control" data-l1key="object_id">
									<option value="">{{Aucun}}</option>
									<?php
									foreach (jeeObject::buildTree(null, false) as $object) {
										echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
									}
									?>
								</select>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Catégorie}}</label>
							<div class="col-sm-8">
								<?php
								foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
									echo '<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'] . '</label>';
								}
								?>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Activer}}</label>
							<div class="col-sm-2">
								<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Visible}}</label>
							<div class="col-sm-2">
								<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
							</div>
						</div>
						<!-- Le type n'est pas modifiable : un équipement ajouté à la main est un
						     hub (preSave), les zones naissent au premier événement de l'appareil
						     ou par « Nommer la zone » dans le journal. Un hub changé en appareil
						     perdrait ses zones. Le champ caché sert à afficher le bon bloc. -->
						<input type="hidden" class="eqLogicAttr" id="in_ajaxsiabeType" data-l1key="configuration" data-l2key="type">
					</fieldset>

					<!-- ============================ HUB ============================ -->
					<fieldset class="ajaxsiabeHubBlock">
						<legend><i class="fas fa-shield-alt"></i> {{Hub}}</legend>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Numéro de compte}}</label>
							<div class="col-sm-3">
								<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="account" placeholder="1234">
							</div>
							<div class="col-sm-5">
								<span class="help-block" style="margin:0;">{{Le « numéro d'objet » saisi dans Ajax PRO : 3 à 16 caractères hexadécimaux selon la norme (Jeedom en accepte de 1 à 16). Rempli tout seul quand le hub est découvert.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Clé de chiffrement}}</label>
							<div class="col-sm-3">
								<input type="password" autocomplete="new-password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="key" placeholder="{{clé générale}}">
							</div>
							<div class="col-sm-5">
								<span class="help-block" style="margin:0;">{{Propre à ce hub : 16, 24 ou 32 caractères, stockée chiffrée. Vide, c'est la clé de la configuration du plugin qui sert. Dès qu'une clé s'applique, les messages en clair de ce hub sont refusés.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Liaison perdue après (min)}}</label>
							<div class="col-sm-2">
								<input type="number" min="0" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="supervision" placeholder="{{auto}}">
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Vide : réglé tout seul sur l'intervalle des tests de liaison envoyés par le hub, à deux tests et demi manqués (trois minutes au moins).}} <span id="span_ajaxsiabeSupervision"></span></span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Créer les appareils}}</label>
							<div class="col-sm-2">
								<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="autozone" checked>
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Un équipement « Zone N » est créé au premier événement de l'appareil Ajax numéro N. Renommez-le d'après l'appareil : son nom apparaît ensuite dans les événements.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Utilisateurs}}</label>
							<div class="col-sm-4">
								<textarea class="eqLogicAttr form-control" rows="4" data-l1key="configuration" data-l2key="users" placeholder="1=Jérôme&#10;2=Clavier entrée"></textarea>
							</div>
							<div class="col-sm-4">
								<span class="help-block" style="margin:0;">{{Un par ligne : numéro=nom. Le numéro est celui que transmet le hub avec un armement ou un désarmement ; le journal le montre.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Groupes}}</label>
							<div class="col-sm-4">
								<textarea class="eqLogicAttr form-control" rows="2" data-l1key="configuration" data-l2key="groups" placeholder="1=Maison&#10;2=Garage"></textarea>
							</div>
							<div class="col-sm-4">
								<span class="help-block" style="margin:0;">{{En mode groupes seulement : numéro=nom. Les déclarer active le suivi du mode groupe par groupe (Armé partiel quand certains seulement sont armés) et nomme le groupe dans les événements. Vide : tout armement vaut pour le système entier.}}</span>
							</div>
						</div>
					</fieldset>

					<!-- ============================ ZONE =========================== -->
					<fieldset class="ajaxsiabeZoneBlock">
						<legend><i class="fas fa-door-open"></i> {{Appareil}}</legend>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Hub}}</label>
							<div class="col-sm-3">
								<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="hub_id">
									<option value="">{{Aucun (hub supprimé)}}</option>
									<?php
									foreach ($hubs as $hub) {
										echo '<option value="' . $hub->getId() . '">' . htmlspecialchars($hub->getName()) . '</option>';
									}
									?>
								</select>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Numéro de zone}}</label>
							<div class="col-sm-2">
								<input type="number" min="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="zone">
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Le numéro de l'appareil dans le hub Ajax, tel qu'il arrive dans les messages.}}</span>
							</div>
						</div>
						<!-- Lien vers l'appareil du plugin ajaxSystem (cloud) : son nom sert
						     dans les événements et « Origine de l'alarme ». Aucune donnée
						     fiable ne relie d'elle-même un numéro de zone SIA à un appareil du
						     cloud : le lien est manuel, avec une suggestion quand le « Numéro
						     de l'équipement » est rempli dans ajaxSystem. -->
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Appareil Ajax (cloud)}}</label>
							<div class="col-sm-4">
								<select class="eqLogicAttr form-control" id="sel_ajaxsiabeCloudDevice" data-l1key="configuration" data-l2key="cloud_eqLogic">
									<option value="">{{Aucun}}</option>
									<?php
									foreach ($cloudDevices as $device) {
										$label = $device['name'] . ($device['room'] !== '' ? ' — ' . $device['room'] : '') . ($device['type'] !== '' ? ' (' . $device['type'] . ')' : '');
										echo '<option value="' . $device['id'] . '" data-name="' . htmlspecialchars($device['name']) . '" data-object="' . htmlspecialchars($device['object'])
											. '" data-number="' . htmlspecialchars($device['number']) . '">' . htmlspecialchars($label) . '</option>';
									}
									?>
								</select>
							</div>
							<div class="col-sm-4">
								<a class="btn btn-default btn-sm" id="bt_ajaxsiabeCloudCopy" title="{{Recopie le nom et la pièce (objet parent) de l'appareil Ajax dans cet équipement. Sauvegardez ensuite.}}"><i class="fas fa-file-import"></i> {{Reprendre nom et pièce}}</a>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Nom Ajax dans les événements}}</label>
							<div class="col-sm-2">
								<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="cloud_name" checked>
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Coché, le nom de l'appareil lié (tel que le cloud Ajax le connaît, renommages compris) sert dans « Origine de l'alarme », « Dernière zone » et les événements. Décoché, c'est le nom de cet équipement.}} <?php echo empty($cloudDevices) ? '{{Aucun appareil du plugin Ajax (ajaxSystem) trouvé.}}' : ''; ?></span>
								<span class="help-block" id="span_ajaxsiabeCloudSuggest" style="margin:0;"></span>
							</div>
						</div>
					</fieldset>
				</form>
			</div>

			<!-- ====================== PILOTAGE PAR LE CLOUD ===================== -->
			<!-- Le SIA ne va que du hub vers Jeedom : les ordres passent par le
			     plugin ajaxSystem (cloud Ajax), et c'est le SIA qui les confirme.
			     Tout est vide par défaut : sans commande réglée, les actions du hub
			     refusent l'ordre au lieu de faire semblant. -->
			<div role="tabpanel" class="tab-pane" id="cloudtab">
				<br>
				<div class="alert alert-info">
					{{Le SIA ne permet pas de commander la centrale : « Armer », « Mode nuit », « Désarmer » et « Panique » exécutent la commande du plugin Ajax (cloud) réglée ici, puis attendent que le hub confirme le nouveau mode par le SIA. Sans confirmation dans le délai, l'ordre est renvoyé, puis déclaré en échec : « Échec du dernier ordre » passe à 1, un message apparaît et les actions ci-dessous sont jouées. Si le SIA indique déjà le mode demandé, rien n'est envoyé.}}
				</div>
				<form class="form-horizontal">
					<fieldset>
						<legend><i class="fas fa-paper-plane"></i> {{Ordres}}</legend>
						<div class="table-responsive">
							<table class="table table-condensed" style="max-width:980px;">
								<thead>
									<tr>
										<th style="width:140px;">{{Ordre}}</th>
										<th>{{Commande du cloud à exécuter}}</th>
										<th style="width:130px;">{{Délai de confirmation (s)}}</th>
										<th style="width:110px;">{{Nouvel(s) essai(s)}}</th>
									</tr>
								</thead>
								<tbody>
									<?php
									$orders = array('arm' => '{{Armer}}', 'night' => '{{Mode nuit}}', 'disarm' => '{{Désarmer}}', 'panic' => '{{Panique (facultatif)}}');
									foreach ($orders as $key => $label) {
										echo '<tr>';
										echo '<td>' . $label . '</td>';
										echo '<td><div class="input-group">';
										echo '<input class="eqLogicAttr form-control input-sm roundedLeft" data-l1key="configuration" data-l2key="order_' . $key . '_cmd" placeholder="{{ex. #[Maison][Ajax hub][Armement]#}}">';
										echo '<span class="input-group-btn"><a class="btn btn-default btn-sm bt_ajaxsiabePickCmd roundedRight" data-type="action" data-key="order_' . $key . '_cmd" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>';
										echo '</div></td>';
										echo '<td><input type="number" min="10" max="3600" class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="order_' . $key . '_delay" placeholder="60"></td>';
										echo '<td><input type="number" min="0" max="5" class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="order_' . $key . '_retries" placeholder="1"></td>';
										echo '</tr>';
									}
									?>
								</tbody>
							</table>
						</div>
						<span class="help-block">{{Avec le plugin Ajax (ajaxSystem) : commandes « Armement », « Mode nuit », « Desarmement » et « Panic » de son hub. Le délai est vérifié chaque minute : compter jusqu'à une minute de plus. La commande du cloud est lancée en tâche de fond : l'action du hub rend la main aussitôt.}}</span>
					</fieldset>

					<fieldset>
						<legend><i class="fas fa-bell"></i> {{Actions en cas d'échec d'un ordre}}
							<a class="btn btn-default btn-xs pull-right bt_ajaxsiabeAddAction" data-list="order_actions"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
						</legend>
						<span class="help-block">{{Jouées une fois quand un ordre reste sans confirmation du SIA après tous ses essais. Balises : #ordre# (Armer…), #mode# (mode visé), #hub#, #essais#, #message# (phrase complète).}}</span>
						<div class="ajaxsiabeActions" data-list="order_actions"></div>
					</fieldset>

					<fieldset>
						<legend><i class="fas fa-balance-scale"></i> {{Surveillance croisée SIA et cloud}}</legend>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Commande d'état du cloud}}</label>
							<div class="col-sm-5">
								<div class="input-group">
									<input class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="cloud_state_cmd" placeholder="{{ex. #[Maison][Ajax hub][Etat]#}}">
									<span class="input-group-btn"><a class="btn btn-default bt_ajaxsiabePickCmd roundedRight" data-type="info" data-key="cloud_state_cmd" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>
								</div>
							</div>
							<div class="col-sm-4">
								<span class="help-block" style="margin:0;">{{Vide : pas de surveillance. Le mode du hub suit toujours le SIA seul ; le cloud n'est que comparé.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Tolérance (min)}}</label>
							<div class="col-sm-2">
								<input type="number" min="0" step="0.5" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="cloud_tolerance" placeholder="2">
							</div>
							<div class="col-sm-6">
								<span class="help-block" style="margin:0;">{{Écart toléré avant alerte : le cloud suit souvent le SIA avec retard. Pas de comparaison pendant un ordre en cours.}}</span>
							</div>
						</div>
						<div class="form-group">
							<label class="col-sm-3 control-label">{{Correspondance des valeurs}}</label>
							<div class="col-sm-4">
								<textarea class="eqLogicAttr form-control" id="ta_ajaxsiabeCloudMap" rows="8" data-l1key="configuration" data-l2key="cloud_state_map" style="font-family:monospace;"></textarea>
							</div>
							<div class="col-sm-4">
								<span class="help-block" style="margin:0;">{{Une ligne par valeur du cloud : valeur=mode, le mode étant Désarmé, Armé, Mode nuit ou Armé partiel (ou disarmed, armed, night, partial). Vide : la table du plugin Ajax (ajaxSystem), préremplie ici. Une valeur absente (PANIC, inconnue) n'est jamais comparée.}}</span>
								<a class="btn btn-default btn-xs" id="bt_ajaxsiabeCloudMapDefault"><i class="fas fa-undo"></i> {{Table par défaut}}</a>
							</div>
						</div>
					</fieldset>

					<fieldset>
						<legend><i class="fas fa-exclamation-triangle"></i> {{Actions en cas de divergence}}
							<a class="btn btn-default btn-xs pull-right bt_ajaxsiabeAddAction" data-list="cloud_actions"><i class="fas fa-plus-circle"></i> {{Ajouter une action}}</a>
						</legend>
						<span class="help-block">{{Jouées une fois par épisode quand le SIA et le cloud divergent au-delà de la tolérance (ou que le SIA se tait alors que le cloud répond), puis une fois au retour à la normale. Balises : #hub#, #mode# (mode SIA), #etat_cloud#, #coherent# (0 à l'alerte, 1 au retour), #message#.}}</span>
						<div class="ajaxsiabeActions" data-list="cloud_actions"></div>
					</fieldset>
				</form>
			</div>

			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="table-responsive">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th>{{Nom}}</th>
								<th>{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th>{{Action}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('core', 'plugin.template', 'js'); ?>
<?php include_file('desktop', 'ajaxsiabe', 'js', 'ajaxsiabe'); ?>
