/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* Appel à core/ajax/ajaxsiabe.ajax.php. */
function ajaxsiabeAjax(_action, _data, _success) {
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/ajaxsiabe/core/ajax/ajaxsiabe.ajax.php',
    data: Object.assign({ action: _action }, _data || {}),
    dataType: 'json',
    global: false,
    noDisplayError: true,
    error: function () {},
    success: function (data) {
      if (data.state == 'ok') {
        _success(data.result)
      }
    }
  })
}

/* « il y a 3 min » : plus parlant qu'une date pour juger d'une liaison. */
function ajaxsiabeAgo(_seconds) {
  if (_seconds < 60) {
    return _seconds + ' s'
  }
  if (_seconds < 3600) {
    return Math.floor(_seconds / 60) + ' min'
  }
  if (_seconds < 86400) {
    return Math.floor(_seconds / 3600) + ' h'
  }
  return Math.floor(_seconds / 86400) + ' j'
}

/* Affiche le bloc de configuration du type d'équipement. */
function ajaxsiabeToggleType() {
  var select = document.getElementById('sel_ajaxsiabeType')
  if (select === null) {
    return
  }
  var zone = (select.value === 'zone')
  document.querySelectorAll('.ajaxsiabeHubBlock').forEach(function (el) {
    el.style.display = zone ? 'none' : ''
  })
  document.querySelectorAll('.ajaxsiabeZoneBlock').forEach(function (el) {
    el.style.display = zone ? '' : 'none'
  })
}

/* Bandeau d'état du récepteur, sur la page des équipements. */
function ajaxsiabeRefreshStatus() {
  var box = document.getElementById('div_ajaxsiabeStatus')
  if (box === null) {
    if (window.ajaxsiabeStatusTimer) {
      clearInterval(window.ajaxsiabeStatusTimer)
      window.ajaxsiabeStatusTimer = null
    }
    return
  }
  ajaxsiabeAjax('status', {}, function (result) {
    var target = document.getElementById('div_ajaxsiabeStatus')
    if (target === null) {
      return
    }
    target.textContent = ''
    var line = document.createElement('div')
    var icon = document.createElement('i')
    if (result.daemon != 'ok' || !result.status) {
      target.className = 'alert alert-danger'
      icon.className = 'fas fa-exclamation-triangle'
      line.appendChild(icon)
      line.appendChild(document.createTextNode(' {{Récepteur arrêté : aucun message ne peut être reçu. Démarrez le démon depuis la page Plugins.}}'))
      target.appendChild(line)
      return
    }
    var status = result.status
    target.className = 'alert alert-success'
    icon.className = 'fas fa-satellite-dish'
    line.appendChild(icon)
    var text = ' {{Récepteur à l\'écoute sur le port}} ' + status.port + (status.udp ? ' (TCP + UDP)' : ' (TCP)')
    text += ' — ' + status.frames + ' {{trame(s) depuis le démarrage}}'
    if (status.rejected > 0) {
      text += ', ' + status.rejected + ' {{refusée(s)}}'
    }
    if (status.lastFrame > 0) {
      text += ' — {{dernière il y a}} ' + ajaxsiabeAgo(result.now - status.lastFrame)
    }
    line.appendChild(document.createTextNode(text))
    target.appendChild(line)

    for (var i = 0; i < result.hubs.length; i++) {
      var hub = result.hubs[i]
      var hubLine = document.createElement('div')
      var hubIcon = document.createElement('i')
      var late = (hub.supervision > 0 && hub.lastContact > 0 && result.now - hub.lastContact > hub.supervision)
      hubIcon.className = late ? 'fas fa-unlink' : 'fas fa-shield-alt'
      hubLine.appendChild(hubIcon)
      var hubText = ' ' + hub.name + ' (#' + hub.account + ') : '
      hubText += (hub.lastContact > 0) ? '{{dernier message il y a}} ' + ajaxsiabeAgo(result.now - hub.lastContact) : '{{aucun message depuis le redémarrage de Jeedom}}'
      if (late) {
        hubText += ' — {{liaison perdue}}'
        target.className = 'alert alert-warning'
      }
      hubLine.appendChild(document.createTextNode(hubText))
      target.appendChild(hubLine)
    }
  })
}

/* Délai de supervision effectif, rappelé sous le champ du hub. */
function ajaxsiabeShowSupervision(_eqLogic) {
  var span = document.getElementById('span_ajaxsiabeSupervision')
  if (span === null) {
    return
  }
  span.textContent = ''
  if (!isset(_eqLogic) || !isset(_eqLogic.id) || _eqLogic.id == '') {
    return
  }
  ajaxsiabeAjax('status', {}, function (result) {
    for (var i = 0; i < result.hubs.length; i++) {
      if (String(result.hubs[i].id) === String(_eqLogic.id)) {
        var delay = result.hubs[i].supervision
        span.textContent = (delay > 0)
          ? '{{Délai appliqué :}} ' + ajaxsiabeAgo(delay) + '.'
          : '{{Pas encore de délai : il faut deux tests de liaison pour mesurer leur intervalle.}}'
      }
    }
  })
}

function printEqLogic(_eqLogic) {
  ajaxsiabeToggleType()
  ajaxsiabeShowSupervision(_eqLogic)
}

/* Les pages sont chargées en AJAX : DOMContentLoaded a déjà eu lieu, les
   écouteurs sont posés à la racine du script. */
var ajaxsiabeContainer = document.getElementById('div_pageContainer') || document.body
ajaxsiabeContainer.addEventListener('change', function (event) {
  if (event.target.closest('#sel_ajaxsiabeType')) {
    ajaxsiabeToggleType()
  }
})

if (window.ajaxsiabeStatusTimer) {
  clearInterval(window.ajaxsiabeStatusTimer)
}
ajaxsiabeRefreshStatus()
window.ajaxsiabeStatusTimer = setInterval(ajaxsiabeRefreshStatus, 10000)

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized" checked>{{Historiser}}</label>'
  tr += '<span class="cmdAttr" data-l1key="htmlstate" style="display:inline-block;margin-left:5px;"></span>'
  tr += '</td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '<a class="btn btn-danger btn-xs cmdAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i></a>'
  tr += '</td>'

  /* Une ligne créée en DOM : insertAdjacentHTML sur la table générerait un
     <tbody> par insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant interne}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}
