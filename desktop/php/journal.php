<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Journal SIA : chaque trame reçue par le démon, acceptée ou non, avec sa
 * lecture en clair. C'est l'outil de mise au point du raccordement (le hub
 * parle-t-il ? la clé est-elle la bonne ?) et la seule façon de savoir ce
 * qu'un hub Ajax émet réellement pour tel ou tel geste.
 *
 * Atteinte par index.php?v=d&m=ajaxsiabe&p=journal.
 */
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$hubs = ajaxsiabe::byTypeAndSearchConfiguration('ajaxsiabe', array('type' => ajaxsiabe::TYPE_HUB));
/* « Aujourd'hui » a une valeur vide : c'est le serveur qui décide du jour,
 * si bien qu'une page restée ouverte après minuit suit le nouveau fichier. */
$dates = array_values(array_diff(ajaxsiabe::journalDates(), array(date('Y-m-d'))));
?>

<div class="row row-overflow" id="div_ajaxsiabeJournal">
	<div class="col-xs-12">
		<legend>
			<a href="index.php?v=d&amp;m=ajaxsiabe&amp;p=ajaxsiabe" class="btn btn-default btn-sm" style="margin-right:10px;"><i class="fas fa-arrow-circle-left"></i> {{Équipements}}</a>
			<i class="fas fa-stream"></i> {{Journal SIA}}
		</legend>

		<form class="form-inline" style="margin-bottom:10px;" onsubmit="return false;">
			<select class="form-control input-sm" id="sel_ajaxsiabeDate">
				<option value="">{{Aujourd'hui}}</option>
				<?php
				foreach ($dates as $date) {
					echo '<option value="' . $date . '">' . date_fr(date('l d F Y', strtotime($date))) . '</option>';
				}
				?>
			</select>
			<select class="form-control input-sm" id="sel_ajaxsiabeAccount">
				<option value="">{{Tous les comptes}}</option>
				<?php
				foreach ($hubs as $hub) {
					if ($hub->getConfiguration('account') == '') {
						continue;
					}
					echo '<option value="' . htmlspecialchars($hub->getConfiguration('account')) . '">' . htmlspecialchars($hub->getName()) . ' (#' . htmlspecialchars($hub->getConfiguration('account')) . ')</option>';
				}
				?>
			</select>
			<input type="text" class="form-control input-sm" id="in_ajaxsiabeSearch" placeholder="{{Rechercher}}">
			<label class="checkbox-inline"><input type="checkbox" id="cb_ajaxsiabeTests"> {{Tests de liaison}}</label>
			<label class="checkbox-inline"><input type="checkbox" id="cb_ajaxsiabeProblems"> {{Refus seulement}}</label>
			<label class="checkbox-inline"><input type="checkbox" id="cb_ajaxsiabeLive" checked> {{Suivi en direct}}</label>
			<span id="span_ajaxsiabeCount" class="label label-default" style="margin-left:10px;"></span>
		</form>

		<div class="alert alert-info" style="margin-bottom:10px;">
			{{Chaque trame reçue est gardée ici, telle quelle et lue en clair ; les tests de liaison automatiques sont masqués tant que la case n'est pas cochée. Pour découvrir ce qu'émet votre hub, laissez « Suivi en direct » coché sur « Aujourd'hui », faites le geste (armer, désarmer, ouvrir une porte, déclencher un sabotage…) et regardez la ligne apparaître. Cliquez sur une ligne pour voir la trame brute.}}
		</div>

		<div class="table-responsive">
			<table class="table table-condensed table-bordered" id="table_ajaxsiabeJournal">
				<thead>
					<tr>
						<th style="width:90px;">{{Heure}}</th>
						<th class="hidden-xs" style="width:160px;">{{Hub}}</th>
						<th>{{Événement}}</th>
						<th class="hidden-xs" style="width:220px;">{{Données SIA}}</th>
						<th style="width:130px;">{{Réception}}</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		</div>
	</div>
</div>

<?php include_file('desktop', 'journal', 'js', 'ajaxsiabe'); ?>
