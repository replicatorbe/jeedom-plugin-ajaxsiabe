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

/* Appel à core/ajax/ajaxsiabe.ajax.php. _failure reçoit un message en cas
   d'échec : session expirée, erreur serveur, réponse refusée. */
function ajaxsiabeAjax(_action, _data, _success, _failure) {
  var fail = function (_message) {
    if (typeof _failure === 'function') {
      _failure(_message)
    }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/ajaxsiabe/core/ajax/ajaxsiabe.ajax.php',
    data: Object.assign({ action: _action }, _data || {}),
    dataType: 'json',
    global: false,
    noDisplayError: true,
    error: function () {
      fail('{{Jeedom ne répond pas, ou la session a expiré.}}')
    },
    success: function (data) {
      if (data.state != 'ok') {
        fail(data.result)
        return
      }
      _success(data.result)
    }
  })
}

/* « il y a 1 h 30 min » : plus parlant qu'une date pour juger d'une liaison. */
function ajaxsiabeAgo(_seconds) {
  var s = Math.max(0, Math.round(_seconds))
  if (s < 60) {
    return s + ' s'
  }
  if (s < 3600) {
    return Math.floor(s / 60) + ' min'
  }
  if (s < 86400) {
    var minutes = Math.floor((s % 3600) / 60)
    return Math.floor(s / 3600) + ' h' + (minutes > 0 ? ' ' + minutes + ' min' : '')
  }
  return Math.floor(s / 86400) + ' j'
}

/* Affiche le bloc de configuration du type d'équipement. */
function ajaxsiabeToggleType() {
  var type = document.getElementById('in_ajaxsiabeType')
  if (type === null) {
    return
  }
  var zone = (type.value === 'zone')
  document.querySelectorAll('.ajaxsiabeHubBlock').forEach(function (el) {
    el.style.display = zone ? 'none' : ''
  })
  document.querySelectorAll('.ajaxsiabeZoneBlock').forEach(function (el) {
    el.style.display = zone ? '' : 'none'
  })
}

/* Remplit le bandeau d'état : une ligne par élément, en texte seulement. */
function ajaxsiabeBanner(_css, _lines) {
  var target = document.getElementById('div_ajaxsiabeStatus')
  if (target === null) {
    return
  }
  target.className = 'alert alert-' + _css
  target.textContent = ''
  for (var i = 0; i < _lines.length; i++) {
    var line = document.createElement('div')
    var icon = document.createElement('i')
    icon.className = 'fas ' + _lines[i][0]
    line.appendChild(icon)
    line.appendChild(document.createTextNode(' ' + _lines[i][1]))
    target.appendChild(line)
  }
}

/* Bandeau d'état du récepteur, sur la page des équipements. Interrogé
   seulement quand il est visible (pas pendant l'édition d'un équipement, ni
   onglet caché), et jamais deux fois à la fois : un Jeedom lent ne doit pas
   voir les requêtes s'empiler. */
function ajaxsiabeRefreshStatus() {
  var box = document.getElementById('div_ajaxsiabeStatus')
  if (box === null) {
    if (window.ajaxsiabeStatusTimer) {
      clearInterval(window.ajaxsiabeStatusTimer)
      window.ajaxsiabeStatusTimer = null
    }
    return
  }
  if (box.offsetParent === null || document.hidden || window.ajaxsiabeStatusBusy) {
    return
  }
  window.ajaxsiabeStatusBusy = true
  /* Une erreur fatale côté serveur n'appelle ni success ni error : sans ce
     délai, le bandeau tournerait indéfiniment. */
  var answered = false
  var watchdog = setTimeout(function () {
    if (!answered) {
      window.ajaxsiabeStatusBusy = false
      ajaxsiabeBanner('info', [['fa-question-circle', '{{État du récepteur inconnu : Jeedom ne répond pas.}}']])
    }
  }, 15000)
  var settle = function () {
    answered = true
    clearTimeout(watchdog)
    window.ajaxsiabeStatusBusy = false
  }
  ajaxsiabeAjax('status', {}, function (result) {
    settle()
    if (result.daemon != 'ok') {
      ajaxsiabeBanner('danger', [['fa-exclamation-triangle', '{{Récepteur arrêté : aucun message ne peut être reçu. Démarrez le démon depuis la page du plugin.}}']])
      return
    }
    var status = result.status
    if (!status) {
      ajaxsiabeBanner('warning', [['fa-question-circle', '{{Le démon tourne mais ne répond pas. Consultez le log ajaxsiabed, ou redémarrez le démon.}}']])
      return
    }
    var lines = []
    var css = 'success'
    if (!status.tcp) {
      css = 'danger'
      lines.push(['fa-exclamation-triangle', '{{Récepteur sourd : le port}} ' + status.port + ' {{n\'a pas pu être ouvert.}} ' + (status.listenError || '')])
    } else {
      var text = '{{Récepteur à l\'écoute sur le port}} ' + status.tcp + (status.udp ? ' (TCP + UDP)' : ' (TCP)')
      if (status.tcp !== status.port) {
        css = 'warning'
        text += ' — {{le port}} ' + status.port + ' {{demandé n\'a pas pu être ouvert, nouvel essai toutes les 30 s}}'
      } else if (status.wantUdp && !status.udp) {
        css = 'warning'
        text += ' — {{UDP n\'a pas pu être ouvert}}'
      }
      lines.push(['fa-satellite-dish', text])
    }
    var counts = status.frames + ' {{trame(s) depuis le démarrage du démon}}'
    if (status.rejected > 0) {
      counts += ', ' + status.rejected + ' {{refusée(s)}}'
    }
    if (status.lastFrame > 0) {
      counts += ' — {{dernière il y a}} ' + ajaxsiabeAgo(result.now - status.lastFrame)
    }
    lines.push(['fa-chart-bar', counts])

    for (var i = 0; i < result.hubs.length; i++) {
      var hub = result.hubs[i]
      /* Même référence que la supervision : jamais avant le démarrage du démon. */
      var since = Math.max(hub.lastContact, result.daemonStart || 0)
      var late = (hub.enabled == 1 && hub.supervision > 0 && since > 0 && result.now - since > hub.supervision)
      var hubText = hub.name + (hub.account ? ' (#' + hub.account + ')' : '') + ' : '
      if (hub.enabled != 1) {
        hubText += '{{désactivé}}'
      } else {
        hubText += (hub.lastContact > 0) ? '{{dernier message il y a}} ' + ajaxsiabeAgo(result.now - hub.lastContact) : '{{aucun message reçu}}'
      }
      if (late) {
        hubText += ' — {{liaison perdue}}'
        if (css === 'success') {
          css = 'warning'
        }
      }
      lines.push([late ? 'fa-unlink' : 'fa-shield-alt', hubText])
    }
    ajaxsiabeBanner(css, lines)
  }, function (_message) {
    settle()
    ajaxsiabeBanner('info', [['fa-question-circle', '{{État du récepteur inconnu :}} ' + _message]])
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
  var wanted = String(_eqLogic.id)
  ajaxsiabeAjax('status', {}, function (result) {
    /* La réponse peut arriver après qu'on a ouvert un autre équipement. */
    var current = document.querySelector('.eqLogicAttr[data-l1key="id"]')
    if (current === null || current.value !== wanted) {
      return
    }
    for (var i = 0; i < result.hubs.length; i++) {
      if (String(result.hubs[i].id) === wanted) {
        var delay = result.hubs[i].supervision
        span.textContent = (delay > 0)
          ? '{{Délai appliqué :}} ' + ajaxsiabeAgo(delay) + '.'
          : '{{Pas encore de délai : il faut trois intervalles entre tests de liaison (quatre tests) pour le mesurer.}}'
      }
    }
  })
}

function printEqLogic(_eqLogic) {
  /* Le coeur ne touche pas une case à cocher dont la clé est absente : elle
     garderait l'état de l'équipement ouvert avant. */
  var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
  var autozone = document.querySelector('.eqLogicAttr[data-l2key="autozone"]')
  if (autozone !== null && !isset(configuration.autozone)) {
    autozone.checked = true
  }
  ajaxsiabeToggleType()
  ajaxsiabeShowSupervision(_eqLogic)
}

/* Copie dans le presse-papiers. navigator.clipboard n'existe qu'en HTTPS ou
   sur localhost : sinon, repli sur une zone de texte temporaire. */
function ajaxsiabeCopy(_text) {
  var done = function () {
    jeedomUtils.showAlert({ message: '{{Copié :}} ' + _text, level: 'success' })
  }
  var warn = function () {
    jeedomUtils.showAlert({ message: '{{Copie impossible, sélectionnez la valeur à la main.}}', level: 'warning' })
  }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(_text).then(done, warn)
    return
  }
  var area = document.createElement('textarea')
  area.value = _text
  area.style.position = 'fixed'
  area.style.opacity = '0'
  document.body.appendChild(area)
  area.select()
  /* execCommand renvoie false sans lever d'exception quand il échoue. */
  var copied = false
  try {
    copied = document.execCommand('copy')
  } catch {
    copied = false
  }
  document.body.removeChild(area)
  if (copied) {
    done()
  } else {
    warn()
  }
}

/* Assistant de raccordement : valeurs à recopier dans Ajax PRO. */
var ajaxsiabeConnection = {}

function ajaxsiabeLoadConnection() {
  if (document.getElementById('div_ajaxsiabeConnect') === null) {
    return
  }
  ajaxsiabeAjax('connection', {}, function (result) {
    ajaxsiabeConnection = result
    ajaxsiabeShowConnection()
  })
}

function ajaxsiabeShowConnection() {
  document.querySelectorAll('.ajaxsiabeConnectValue').forEach(function (el) {
    var field = el.getAttribute('data-field')
    var value = ajaxsiabeConnection[field]
    el.textContent = (value === undefined || value === '') ? '{{aucune}}' : String(value)
  })
  var button = document.getElementById('bt_ajaxsiabeGenerateKey')
  if (button !== null) {
    button.querySelector('span').textContent = ajaxsiabeConnection.key ? '{{Remplacer}}' : '{{Générer une clé}}'
  }
  /* Rien à copier : ni bouton, ni texte d'aide pris pour une valeur. */
  document.querySelectorAll('.bt_ajaxsiabeCopy').forEach(function (el) {
    var value = ajaxsiabeConnection[el.getAttribute('data-field')]
    el.style.display = (value === undefined || value === '' || value === null) ? 'none' : ''
  })
  var noIp = document.getElementById('span_ajaxsiabeNoIp')
  if (noIp !== null) {
    noIp.style.display = ajaxsiabeConnection.ip ? 'none' : ''
  }
}

function ajaxsiabeGenerateKey() {
  var go = function () {
    ajaxsiabeAjax('generateKey', {}, function (result) {
      ajaxsiabeConnection.key = result.key
      ajaxsiabeShowConnection()
      jeedomUtils.showAlert({ message: '{{Clé enregistrée dans la configuration du plugin. Saisissez-la dans Ajax PRO et activez le chiffrement.}}', level: 'success' })
    }, function (_message) {
      jeedomUtils.showAlert({ message: _message, level: 'danger' })
    })
  }
  /* Une clé change tout : dès qu'elle existe, les messages en clair sont
     refusés, et remplacer l'ancienne coupe les hubs qui l'utilisent. */
  var message = ajaxsiabeConnection.key
    ? '{{Remplacer la clé ? Les hubs qui utilisent l\'ancienne ne seront plus acceptés tant que la nouvelle n\'est pas saisie dans Ajax PRO.}}'
    : '{{Générer une clé ? Dès qu\'elle existe, tout message en clair est refusé : activez le chiffrement dans Ajax PRO avec cette clé, sinon le hub ne sera plus entendu.}}'
  jeeDialog.confirm(message, function (result) {
    if (result) {
      go()
    }
  })
}

/* Les pages sont chargées en AJAX : DOMContentLoaded a déjà eu lieu, les
   écouteurs sont posés à la racine du script. */
var ajaxsiabeContainer = document.getElementById('div_pageContainer') || document.body
function ajaxsiabeToggleConnect() {
  var body = document.getElementById('div_ajaxsiabeConnectBody')
  var open = (body.style.display === 'none')
  body.style.display = open ? '' : 'none'
  document.getElementById('bt_ajaxsiabeConnectToggle').setAttribute('aria-expanded', open ? 'true' : 'false')
}
ajaxsiabeContainer.addEventListener('keydown', function (event) {
  if ((event.key === 'Enter' || event.key === ' ') && event.target.closest('#bt_ajaxsiabeConnectToggle')) {
    event.preventDefault()
    ajaxsiabeToggleConnect()
  }
})
ajaxsiabeContainer.addEventListener('click', function (event) {
  var copy = event.target.closest('.bt_ajaxsiabeCopy')
  if (copy !== null) {
    event.preventDefault()
    var value = ajaxsiabeConnection[copy.getAttribute('data-field')]
    if (value !== undefined && value !== '') {
      ajaxsiabeCopy(String(value))
    }
    return
  }
  if (event.target.closest('#bt_ajaxsiabeGenerateKey')) {
    event.preventDefault()
    ajaxsiabeGenerateKey()
    return
  }
  if (event.target.closest('#bt_ajaxsiabeConnectToggle')) {
    ajaxsiabeToggleConnect()
  }
})

var ajaxsiabeConnectBody = document.getElementById('div_ajaxsiabeConnectBody')
if (ajaxsiabeConnectBody !== null) {
  var ajaxsiabeConnectOpen = (ajaxsiabeConnectBody.getAttribute('data-open') === '1')
  ajaxsiabeConnectBody.style.display = ajaxsiabeConnectOpen ? '' : 'none'
  document.getElementById('bt_ajaxsiabeConnectToggle').setAttribute('aria-expanded', ajaxsiabeConnectOpen ? 'true' : 'false')
}
ajaxsiabeLoadConnection()

if (window.ajaxsiabeStatusTimer) {
  clearInterval(window.ajaxsiabeStatusTimer)
}
window.ajaxsiabeStatusBusy = false
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
  /* Pas de bouton de suppression : les commandes sont celles du plugin, et
     l'enregistrement suivant les recréerait. */
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
