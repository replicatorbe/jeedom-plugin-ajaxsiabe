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
  window: { label: '{{Mal daté}}', css: 'warning' },
  timestamp: { label: '{{Sans heure}}', css: 'warning' },
  decrypt: { label: '{{Clé fausse}}', css: 'danger' },
  crc: { label: '{{CRC faux}}', css: 'danger' },
  length: { label: '{{Longueur fausse}}', css: 'danger' },
  format: { label: '{{Illisible}}', css: 'danger' },
  refused: { label: '{{Refusé}}', css: 'danger' }
}

function ajaxsiabeJournalCell(_row, _text, _className) {
  var td = document.createElement('td')
  td.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  if (_className) {
    td.className = _className
  }
  _row.appendChild(td)
  return td
}

function ajaxsiabeJournalTime(_t) {
  var d = new Date(_t * 1000)
  var pad = function (n) { return (n < 10 ? '0' : '') + n }
  return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
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
  if (_entry.reply) {
    lines.push('{{Réponse}} : ' + _entry.reply)
  }
  if (_entry.skew !== null && _entry.skew !== undefined) {
    lines.push('{{Écart d\'horloge du hub}} : ' + _entry.skew + ' s')
  }
  var events = _entry.events || []
  for (var i = 0; i < events.length; i++) {
    var e = events[i]
    var parts = [e.code + (e.addr ? ' ' + e.addr : ''), e.label || '']
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

function ajaxsiabeJournalRender(_result) {
  var tbody = document.querySelector('#table_ajaxsiabeJournal tbody')
  if (tbody === null) {
    return
  }
  var open = {}
  tbody.querySelectorAll('tr.ajaxsiabeDetail').forEach(function (tr) {
    open[tr.getAttribute('data-key')] = true
  })
  tbody.textContent = ''

  var count = document.getElementById('span_ajaxsiabeCount')
  if (count !== null) {
    count.textContent = _result.total + ' {{trame(s)}}' + (_result.truncated ? ' ({{les plus récentes affichées}})' : '')
  }
  if (_result.entries.length === 0) {
    var empty = document.createElement('tr')
    var td = ajaxsiabeJournalCell(empty, '{{Aucune trame pour ce jour et ces filtres.}}')
    td.colSpan = 5
    td.style.textAlign = 'center'
    tbody.appendChild(empty)
    return
  }

  _result.entries.forEach(function (entry) {
    var key = String(entry.t) + '|' + (entry.seq || '') + '|' + (entry.peer || '')
    var row = document.createElement('tr')
    row.style.cursor = 'pointer'
    row.setAttribute('data-key', key)
    ajaxsiabeJournalCell(row, ajaxsiabeJournalTime(entry.t))
    ajaxsiabeJournalCell(row, entry.hub ? entry.hub : (entry.account ? '#' + entry.account : (entry.peer || '')))
    var text = entry.text || entry.error || ''
    ajaxsiabeJournalCell(row, text)
    ajaxsiabeJournalCell(row, (entry.type === 'NULL') ? 'NULL' : (entry.data || ''), 'text-muted')
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
    if (entry.status !== 'ok' && entry.status !== 'duplicate') {
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
    detail.appendChild(cell)
    detail.style.display = open[key] ? '' : 'none'
    tbody.appendChild(detail)

    row.addEventListener('click', function () {
      detail.style.display = (detail.style.display === 'none') ? '' : 'none'
    })
  })
}

function ajaxsiabeJournalLoad() {
  if (document.getElementById('table_ajaxsiabeJournal') === null) {
    return false
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
    error: function () {},
    success: function (data) {
      if (data.state != 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' })
        return
      }
      ajaxsiabeJournalRender(data.result)
    }
  })
  return true
}

/* Suivi en direct : le jour affiché doit être aujourd'hui, sinon rien ne peut
   y arriver. Le minuteur s'arrête seul quand on quitte la page. */
function ajaxsiabeJournalTick() {
  if (document.getElementById('table_ajaxsiabeJournal') === null) {
    clearInterval(window.ajaxsiabeJournalTimer)
    window.ajaxsiabeJournalTimer = null
    return
  }
  var live = document.getElementById('cb_ajaxsiabeLive')
  var date = document.getElementById('sel_ajaxsiabeDate')
  if (live.checked && date.selectedIndex === 0) {
    ajaxsiabeJournalLoad()
  }
}

var ajaxsiabeJournalRoot = document.getElementById('div_ajaxsiabeJournal')
if (ajaxsiabeJournalRoot !== null) {
  ajaxsiabeJournalRoot.addEventListener('change', function (event) {
    if (event.target.closest('select, input[type=checkbox]')) {
      ajaxsiabeJournalLoad()
    }
  })
  var ajaxsiabeSearchDelay = null
  ajaxsiabeJournalRoot.addEventListener('input', function (event) {
    if (event.target.id === 'in_ajaxsiabeSearch') {
      clearTimeout(ajaxsiabeSearchDelay)
      ajaxsiabeSearchDelay = setTimeout(ajaxsiabeJournalLoad, 300)
    }
  })
}

if (window.ajaxsiabeJournalTimer) {
  clearInterval(window.ajaxsiabeJournalTimer)
}
ajaxsiabeJournalLoad()
window.ajaxsiabeJournalTimer = setInterval(ajaxsiabeJournalTick, 3000)
