<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$internalAddr = config::byKey('internalAddr', 'core', '');
?>
<form class="form-horizontal">
	<fieldset>
		<legend><i class="fas fa-satellite-dish"></i> {{Réception SIA}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port de réception}}</label>
			<div class="col-md-2">
				<input type="number" min="1024" max="65535" class="configKey form-control" data-l1key="port" placeholder="7777">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Port à saisir dans l'application Ajax PRO, avec l'adresse de Jeedom}} <?php echo ($internalAddr != '') ? '(<b>' . htmlspecialchars($internalAddr) . '</b>)' : ''; ?>. {{Entre 1024 et 65535.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Écouter aussi en UDP}}</label>
			<div class="col-md-2">
				<input type="checkbox" class="configKey" data-l1key="udp">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Les hubs Ajax émettent en TCP ; l'UDP sert à d'autres centrales. Le même port est ouvert dans les deux cas.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Clé de chiffrement}}</label>
			<div class="col-md-3">
				<input type="password" autocomplete="new-password" class="configKey form-control" data-l1key="key" placeholder="{{16, 24 ou 32 caractères}}">
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{La clé saisie dans Ajax PRO. Elle sert aux hubs qui n'ont pas la leur, et permet de découvrir un hub dont les messages sont chiffrés. Laisser vide si le chiffrement n'est pas activé.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Refuser les messages mal datés}}</label>
			<div class="col-md-2">
				<input type="checkbox" class="configKey" data-l1key="strict_time">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Conforme à la norme : un message chiffré daté de plus de 40 s dans le passé ou 20 s dans le futur est refusé, ce qui empêche de rejouer une trame capturée. Le refus donne l'heure au hub, qui se recale et renvoie. Décocher seulement si le journal montre des refus répétés pour ce motif.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Adresses autorisées}}</label>
			<div class="col-md-3">
				<input class="configKey form-control" data-l1key="allowed" placeholder="{{toutes}}">
			</div>
			<div class="col-md-4">
				<span class="help-block" style="margin:0;">{{Adresses IP des hubs, séparées par des virgules. Vide : tout émetteur est accepté. Les connexions refusées figurent au journal.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-shield-alt"></i> {{Hubs et appareils}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Créer les hubs inconnus}}</label>
			<div class="col-md-2">
				<input type="checkbox" class="configKey" data-l1key="autocreate">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Un équipement est créé au premier message d'un numéro de compte inconnu. Décoché, ces messages sont seulement accusés et gardés au journal.}}</span>
			</div>
		</div>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Journal conservé (jours)}}</label>
			<div class="col-md-2">
				<input type="number" min="1" max="3650" class="configKey form-control" data-l1key="journal_days" placeholder="90">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Chaque trame reçue y est gardée, y compris les tests de liaison et les messages refusés.}}</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><i class="fas fa-plug"></i> {{Démon}}</legend>
		<div class="form-group">
			<label class="col-md-4 control-label">{{Port des ordres (local)}}</label>
			<div class="col-md-2">
				<input type="number" class="configKey form-control" data-l1key="socketport" placeholder="55065">
			</div>
			<div class="col-md-5">
				<span class="help-block" style="margin:0;">{{Port sur 127.0.0.1 par lequel Jeedom parle au démon. À changer uniquement en cas de conflit, puis redémarrer le démon.}}</span>
			</div>
		</div>
	</fieldset>
</form>
