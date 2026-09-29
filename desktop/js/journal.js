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

/* Tout ce qui s'affiche vient du réseau : les cellules sont remplies par
   textContent, jamais par innerHTML. */

var ajaxsiabeJournalStatus = {
  ok: { label: '{{Accepté}}', css: 'success' },
  duplicate: { label: '{{Doublon}}', css: 'info' },
  plain: { label: '{{En clair}}', css: 'danger' },
  window: { label: '{{Mal daté}}', css: 'warning' },
  timestamp: { label: '{{Sans heure}}', css: 'warning' },
  decrypt: { label: '{{Clé fausse}}', css: 'danger' },
  crc: { label: '{{CRC faux}}', css: 'danger' },
  format: { label: '{{Illisible}}', css: 'danger' },
  refused: { label: '{{Refusé}}', css: 'danger' },
  /* Pas une trame : ce que Jeedom a fait lui-même (ordre passé par le cloud,
     confirmation par le SIA, alerte de la surveillance croisée). */
  jeedom: { label: '{{Jeedom}}', css: 'primary' }
}

/* Numéro de la dernière requête partie, et signature du dernier rendu. */
var ajaxsiabeJournalRequest = 0
var ajaxsiabeJournalSignature = ''
var ajaxsiabeJournalErrorShown = false
/* Requête en cours (numéro), et depuis quand : le rafraîchissement en direct
   attend sa réponse au lieu d'empiler les requêtes sur un Jeedom lent. */
var ajaxsiabeJournalBusy = 0
var ajaxsiabeJournalBusySince = 0

function ajaxsiabeJournalCell(_row, _text, _className) {
  var td = document.createElement('td')
  td.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  if (_className) {
    td.className = _className
  }
  _row.appendChild(td)
  return td
}

/* Détail d'une trame, ouvert sous sa ligne. */
function ajaxsiabeJournalDetail(_entry) {
  var lines = []
  lines.push('{{Émetteur}} : ' + (_entry.peer || '?') + ' (' + (_entry.proto || '?') + ')')
  if (_entry.type) {
    lines.push('{{Type}} : ' + _entry.type + (_entry.enc ? ' ({{chiffré}})' : ' ({{en clair}})') + ' — {{séquence}} ' + _entry.seq + ' — {{compte}} ' + _entry.account)
  }
  if (_entry.error) {
    lines.push('{{Motif}} : ' + _entry.error)
  }
  if (_entry.warning) {
    lines.push('{{Remarque}} : ' + _entry.warning)
  }
  if (_entry.note) {
    lines.push('{{Jeedom}} : ' + _entry.note)
  }
  if (_entry.reply) {
    lines.push('{{Réponse}} : ' + _entry.reply)
  }
  if (_entry.skew !== null && _entry.skew !== undefined) {
    lines.push('{{Écart d\'horloge du hub}} : ' + _entry.skew + ' s')
  }
  var events = _entry.events || []
  for (var i = 0; i < events.length; i++) {
    var e = events[i]
    var parts = [(e.code || '?') + (e.addr ? ' ' + e.addr : ''), e.label || '']
    if (e.ri) {
      parts.push('ri ' + e.ri)
    }
    if (e.id) {
      parts.push('id ' + e.id)
    }
    if (e.cid) {
      parts.push('Contact ID ' + e.cid)
    }
    lines.push('{{Événement}} : ' + parts.join(' — '))
  }
  if (_entry.xdata && _entry.xdata.length > 0) {
    lines.push('{{Extensions}} : [' + _entry.xdata.join('] [') + ']')
  }
  if (_entry.content) {
    lines.push('{{Contenu déchiffré}} : ' + _entry.content)
  }
  if (_entry.raw) {
    lines.push('{{Trame brute}} : ' + _entry.raw)
  }
  return lines.join('\n')
}

function ajaxsiabeJournalKey(_entry) {
  return String(_entry.t) + '|' + (_entry.seq || '') + '|' + (_entry.peer || '') + '|' + (_entry.status || '')
}

function ajaxsiabeJournalRender(_result) {
  var tbody = document.querySelector('#table_ajaxsiabeJournal tbody')
  if (tbody === null) {
    return
  }
  /* Rien de neuf : on ne touche pas au tableau, pour ne pas perdre une ligne
     ouverte ni une sélection en cours. */
  var signature = _result.date + '|' + _result.total + '|' + _result.hiddenTests + '|'
    + (_result.entries.length > 0 ? ajaxsiabeJournalKey(_result.entries[0]) : '')
  if (signature === ajaxsiabeJournalSignature) {
    return
  }
  ajaxsiabeJournalSignature = signature

  var open = {}
  tbody.querySelectorAll('tr.ajaxsiabeDetail').forEach(function (tr) {
    if (tr.style.display !== 'none') {
      open[tr.getAttribute('data-key')] = true
    }
  })
  tbody.textContent = ''

  var count = document.getElementById('span_ajaxsiabeCount')
  if (count !== null) {
    var text = _result.total + ' {{trame(s)}}'
    if (_result.truncated) {
      text += ' ({{les plus récentes affichées}})'
    }
    if (_result.hiddenTests > 0) {
      text += ' — ' + _result.hiddenTests + ' {{test(s) de liaison masqué(s)}}'
    }
    count.textContent = text
  }
  if (_result.entries.length === 0) {
    var empty = document.createElement('tr')
    var message = '{{Aucune trame pour ce jour et ces filtres.}}'
    if (_result.hiddenTests > 0) {
      message += ' ' + _result.hiddenTests + ' {{test(s) de liaison masqué(s) : cochez « Tests de liaison » pour les voir.}}'
    }
    var td = ajaxsiabeJournalCell(empty, message)
    td.colSpan = 5
    td.style.textAlign = 'center'
    tbody.appendChild(empty)
    return
  }

  _result.entries.forEach(function (entry) {
    var key = ajaxsiabeJournalKey(entry)
    var row = document.createElement('tr')
    row.style.cursor = 'pointer'
    row.tabIndex = 0
    ajaxsiabeJournalCell(row, entry.time || '')
    ajaxsiabeJournalCell(row, entry.hub ? entry.hub : (entry.account ? '#' + entry.account : (entry.peer || '')), 'hidden-xs')
    ajaxsiabeJournalCell(row, entry.text || entry.error || '')
    ajaxsiabeJournalCell(row, (entry.type === 'NULL') ? 'NULL' : (entry.data || ''), 'text-muted hidden-xs')
    var statusCell = ajaxsiabeJournalCell(row, '')
    var status = ajaxsiabeJournalStatus[entry.status] || { label: entry.status, css: 'default' }
    var badge = document.createElement('span')
    badge.className = 'label label-' + status.css
    badge.textContent = status.label
    statusCell.appendChild(badge)
    if (entry.enc) {
      var lock = document.createElement('i')
      lock.className = 'fas fa-lock'
      lock.title = '{{Message chiffré}}'
      lock.style.marginLeft = '5px'
      statusCell.appendChild(lock)
    }
    if (entry.warning) {
      var warn = document.createElement('i')
      warn.className = 'fas fa-exclamation-circle'
      warn.title = entry.warning
      warn.style.marginLeft = '5px'
      statusCell.appendChild(warn)
    }
    if (entry.status !== 'ok' && entry.status !== 'duplicate' && entry.status !== 'jeedom') {
      row.classList.add('warning')
    }
    tbody.appendChild(row)

    var detail = document.createElement('tr')
    detail.className = 'ajaxsiabeDetail'
    detail.setAttribute('data-key', key)
    var cell = document.createElement('td')
    cell.colSpan = 5
    var pre = document.createElement('pre')
    pre.style.whiteSpace = 'pre-wrap'
    pre.style.wordBreak = 'break-all'
    pre.style.margin = '0'
    pre.textContent = ajaxsiabeJournalDetail(entry)
    cell.appendChild(pre)
    ajaxsiabeJournalNameButtons(cell, entry)
    detail.appendChild(cell)
    detail.style.display = open[key] ? '' : 'none'
    tbody.appendChild(detail)
  })
}

/* Boutons « Nommer la zone N » et « Nommer l'utilisateur N » sous le détail :
   on nomme au moment même où l'on voit arriver l'événement de son geste. */
function ajaxsiabeJournalNameButtons(_cell, _entry) {
  if (!_entry.hubId) {
    return
  }
  var seen = {}
  var bar = document.createElement('div')
  bar.style.marginTop = '5px'
  var events = _entry.events || []
  events.forEach(function (e) {
    var targets = []
    if (e.zoneNumber > 0) {
      targets.push({ kind: 'zone', number: e.zoneNumber, current: e.zoneName || '', label: '{{Nommer la zone}} ' + e.zoneNumber })
    }
    if (e.userNumber !== null && e.userNumber !== undefined && e.userNumber > 0) {
      targets.push({ kind: 'user', number: e.userNumber, current: e.userName || '', label: '{{Nommer l\'utilisateur}} ' + e.userNumber })
    }
    targets.forEach(function (t) {
      var key = t.kind + t.number
      if (seen[key]) {
        return
      }
      seen[key] = true
      var button = document.createElement('a')
      button.className = 'btn btn-xs btn-default bt_ajaxsiabeName'
      button.href = '#'
      button.setAttribute('role', 'button')
      button.style.marginRight = '5px'
      button.setAttribute('data-kind', t.kind)
      button.setAttribute('data-number', t.number)
      button.setAttribute('data-hub', _entry.hubId)
      button.setAttribute('data-current', t.current)
      var icon = document.createElement('i')
      icon.className = (t.kind === 'zone') ? 'fas fa-door-open' : 'fas fa-user'
      button.appendChild(icon)
      button.appendChild(document.createTextNode(' ' + t.label + (t.current ? ' (' + t.current + ')' : '')))
      bar.appendChild(button)
    })
  })
  if (bar.childNodes.length > 0) {
    _cell.appendChild(bar)
  }
}

function ajaxsiabeJournalName(_button) {
  var kind = _button.getAttribute('data-kind')
  var number = _button.getAttribute('data-number')
  var current = _button.getAttribute('data-current') || ''
  /* Un nom par défaut (« Zone 12 », « utilisateur 5 ») n'est pas proposé
     comme valeur de départ. */
  var isDefault = new RegExp('^(zone|utilisateur) [0-9]+$', 'i').test(current)
  jeeDialog.prompt({
    title: (kind === 'zone' ? '{{Nom de l\'appareil}} ' : '{{Nom de l\'utilisateur}} ') + number,
    value: isDefault ? '' : current,
    callback: function (result) {
      if (result === null || String(result).trim() === '') {
        return
      }
      domUtils.ajax({
        type: 'POST',
        url: 'plugins/ajaxsiabe/core/ajax/ajaxsiabe.ajax.php',
        data: { action: (kind === 'zone') ? 'nameZone' : 'nameUser', hub_id: _button.getAttribute('data-hub'), number: number, name: String(result).trim() },
        dataType: 'json',
        global: false,
        noDisplayError: true,
        error: function () {
          jeedomUtils.showAlert({ message: '{{Enregistrement impossible : Jeedom ne répond pas, ou la session a expiré.}}', level: 'danger' })
        },
        success: function (data) {
          if (data.state != 'ok') {
            jeedomUtils.showAlert({ message: data.result, level: 'danger' })
            return
          }
          jeedomUtils.showAlert({ message: '{{Nom enregistré : il s\'applique aussi aux événements passés.}}', level: 'success' })
          ajaxsiabeJournalLoad(true)
        }
      })
    }
  })
}

/* Un seul écouteur pour toutes les lignes : ouvre ou ferme le détail. */
function ajaxsiabeJournalToggle(event) {
  var name = event.target.closest('.bt_ajaxsiabeName')
  if (name !== null) {
    event.preventDefault()
    ajaxsiabeJournalName(name)
    return
  }
  var row = event.target.closest('#table_ajaxsiabeJournal tbody tr')
  if (row === null || row.classList.contains('ajaxsiabeDetail')) {
    return
  }
  var detail = row.nextElementSibling
  if (detail !== null && detail.classList.contains('ajaxsiabeDetail')) {
    detail.style.display = (detail.style.display === 'none') ? '' : 'none'
  }
}

function ajaxsiabeJournalLoad(_force) {
  if (document.getElementById('table_ajaxsiabeJournal') === null) {
    return
  }
  if (_force) {
    ajaxsiabeJournalSignature = ''
  }
  /* Chaque requête est numérotée : une réponse arrivée après une plus récente
     (changement de filtre pendant un rafraîchissement) est ignorée. */
  var request = ++ajaxsiabeJournalRequest
  ajaxsiabeJournalBusy = request
  ajaxsiabeJournalBusySince = Date.now()
  var settle = function () {
    if (ajaxsiabeJournalBusy === request) {
      ajaxsiabeJournalBusy = 0
    }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/ajaxsiabe/core/ajax/ajaxsiabe.ajax.php',
    data: {
      action: 'journal',
      date: document.getElementById('sel_ajaxsiabeDate').value,
      account: document.getElementById('sel_ajaxsiabeAccount').value,
      search: document.getElementById('in_ajaxsiabeSearch').value,
      tests: document.getElementById('cb_ajaxsiabeTests').checked ? 1 : 0,
      problems: document.getElementById('cb_ajaxsiabeProblems').checked ? 1 : 0
    },
    dataType: 'json',
    global: false,
    noDisplayError: true,
    error: function () {
      settle()
      ajaxsiabeJournalError('{{Journal injoignable : la session a peut-être expiré.}}')
    },
    success: function (data) {
      settle()
      if (request !== ajaxsiabeJournalRequest) {
        return
      }
      if (data.state != 'ok') {
        ajaxsiabeJournalError(data.result)
        return
      }
      ajaxsiabeJournalErrorShown = false
      ajaxsiabeJournalRender(data.result)
    }
  })
}

/* Une erreur n'est annoncée qu'une fois, pas toutes les trois secondes. */
function ajaxsiabeJournalError(_message) {
  if (ajaxsiabeJournalErrorShown) {
    return
  }
  ajaxsiabeJournalErrorShown = true
  jeedomUtils.showAlert({ message: _message, level: 'danger' })
}

/* Le suivi en direct n'a de sens que sur « Aujourd'hui ». */
function ajaxsiabeJournalLiveState() {
  var live = document.getElementById('cb_ajaxsiabeLive')
  var date = document.getElementById('sel_ajaxsiabeDate')
  if (live === null || date === null) {
    return
  }
  live.disabled = (date.value !== '')
  live.parentNode.style.opacity = live.disabled ? '0.5' : ''
  live.parentNode.title = live.disabled ? '{{Seulement sur « Aujourd\'hui »}}' : ''
}

/* Rafraîchit en direct, sauf pendant une sélection de texte : elle serait
   perdue au moment même où on veut copier une trame. */
function ajaxsiabeJournalTick() {
  if (document.getElementById('table_ajaxsiabeJournal') === null) {
    clearInterval(window.ajaxsiabeJournalTimer)
    window.ajaxsiabeJournalTimer = null
    return
  }
  var live = document.getElementById('cb_ajaxsiabeLive')
  var date = document.getElementById('sel_ajaxsiabeDate')
  if (!live.checked || date.value !== '' || document.hidden) {
    return
  }
  /* Une erreur fatale côté serveur n'appelle ni success ni error : au-delà
     de 30 s, la requête est tenue pour perdue. */
  if (ajaxsiabeJournalBusy !== 0 && Date.now() - ajaxsiabeJournalBusySince < 30000) {
    return
  }
  var selection = window.getSelection ? String(window.getSelection()) : ''
  if (selection !== '') {
    return
  }
  ajaxsiabeJournalLoad(false)
}

var ajaxsiabeJournalRoot = document.getElementById('div_ajaxsiabeJournal')
if (ajaxsiabeJournalRoot !== null) {
  ajaxsiabeJournalRoot.addEventListener('change', function (event) {
    if (event.target.id === 'sel_ajaxsiabeDate') {
      ajaxsiabeJournalLiveState()
    }
    if (event.target.closest('select, input[type=checkbox]') && event.target.id !== 'cb_ajaxsiabeLive') {
      ajaxsiabeJournalLoad(true)
    }
  })
  var ajaxsiabeSearchDelay = null
  ajaxsiabeJournalRoot.addEventListener('input', function (event) {
    if (event.target.id === 'in_ajaxsiabeSearch') {
      clearTimeout(ajaxsiabeSearchDelay)
      ajaxsiabeSearchDelay = setTimeout(function () { ajaxsiabeJournalLoad(true) }, 300)
    }
  })
  ajaxsiabeJournalRoot.addEventListener('click', ajaxsiabeJournalToggle)
  /* Au clavier : Entrée sur une ligne ouvre son détail. */
  ajaxsiabeJournalRoot.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && event.target.matches('#table_ajaxsiabeJournal tbody tr')) {
      event.preventDefault()
      ajaxsiabeJournalToggle(event)
    }
  })
}

if (window.ajaxsiabeJournalTimer) {
  clearInterval(window.ajaxsiabeJournalTimer)
}
ajaxsiabeJournalBusy = 0
ajaxsiabeJournalLiveState()
ajaxsiabeJournalLoad(true)
window.ajaxsiabeJournalTimer = setInterval(ajaxsiabeJournalTick, 3000)
