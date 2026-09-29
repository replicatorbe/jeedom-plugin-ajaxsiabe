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
