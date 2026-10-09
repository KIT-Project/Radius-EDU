/* School presentation layer. Server endpoints and their responses remain unchanged. */
(function () {
    'use strict';
    window.schoolUi = { enabled: true };
    var allowed = ['cPermanentUsers', 'cActivityMonitor', 'cSettings', 'cAccessProviders', 'cAuditLogs', 'cDynamicDetails'];
    function escape(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function filterRows(items) {
        var result = [], header;
        (items || []).forEach(function (row) {
            if (row.rowType) { header = row; return; }
            if (row.id && !row.column1 && !row.column2) { if (row.id !== 'cNetworkOverview') result.push(row); return; }
            var columns = Object.keys(row).map(function (key) { return row[key]; }).filter(function (column) {
                return column && allowed.indexOf(column.controller) !== -1;
            });
            if (!columns.length) return;
            if (header) { result.push(header); header = null; }
            var filtered = {};
            columns.forEach(function (column, i) { filtered['column' + (i + 1)] = column; });
            result.push(filtered);
        });
        return result;
    }
    function request(endpoint, params) {
        return new Promise(function (resolve, reject) {
            Ext.Ajax.request({url:'/cake4/rd_cake/' + endpoint + '.json', method:'GET', params:params,
                schoolRaw:true,
                success:function (response) {
                    try { var data = JSON.parse(response.responseText); if (!data.success) throw new Error('API rejected'); resolve(data); }
                    catch (error) { reject(error); }
                }, failure:reject});
        });
    }
    // SQL accounting timestamps are UTC; never interpret them as browser local time.
    function accountingTime(value) {
        if (!value) return NaN;
        var text = String(value).replace(' ', 'T');
        if (!/(Z|[+-]\d{2}:?\d{2})$/i.test(text)) text += 'Z';
        return Date.parse(text);
    }
    function duration(seconds) {
        seconds = Math.max(0, Math.floor(seconds));
        return [Math.floor(seconds/3600), Math.floor(seconds%3600/60), seconds%60]
            .map(function (n) { return String(n).padStart(2, '0'); }).join(':');
    }
    function tickSessions() {
        document.querySelectorAll('[data-school-seconds]').forEach(function (cell) {
            var elapsed = Math.max(0, (Date.now() - Number(cell.dataset.schoolAnchor))/1000);
            cell.textContent = duration(Number(cell.dataset.schoolSeconds) + elapsed);
        });
    }
    function render(node, users, nas, profiles, sessions) {
        var u = {};
        (users.items || []).forEach(function (row) {
            Object.keys(row).forEach(function (key) {
                if (row[key] && row[key].controller === 'cPermanentUsers') u = row[key];
            });
        });
        var total = Number(u.total || 0), online = Number(u.online || 0);
        var percent = total ? Math.min(100, Math.round(100 * online / total)) : 0;
        var cards = [[total,'บัญชีผู้ใช้','mint','fa-users'],[online+' / '+total,'ผู้ใช้ออนไลน์ / ผู้ใช้ทั้งหมด','purple','fa-wifi'],
            [nas.totalCount,'NAS ที่กำหนดไว้','blue','fa-server'],[profiles.totalCount,'นโยบายผู้ใช้','peach','fa-sliders']];
        var html = '<header><small>NETWORK MANAGEMENT</small><h2>Dashboard</h2></header><div class="school-stats">';
        cards.forEach(function (card) {
            html += '<article class="school-stat '+card[2]+'"><strong>'+escape(card[0])+'</strong><span>'+card[1]+'</span><i class="fa '+card[3]+'" aria-hidden="true"></i></article>';
        });
        html += '</div><div class="school-panels"><section class="school-widget"><h3>สถานะผู้ใช้</h3><div class="school-donut" style="--online:'+percent+'%"><div><strong>'+online+' / '+total+'</strong><span>ออนไลน์ '+percent+'%</span></div></div><div class="school-legend"><span style="color:#50bc98">● ออนไลน์ '+online+'</span><span style="color:#8592a3">● ออฟไลน์ '+Math.max(0,total-online)+'</span></div></section>';
        html += '<section class="school-widget"><h3>สรุปบัญชีและนโยบาย</h3><dl>';
        [[total,'ผู้ใช้ทั้งหมด'],[nas.totalCount,'NAS ที่ลงทะเบียน'],[profiles.totalCount,'Profiles']].forEach(function (item) {
            html += '<div><dt>'+item[1]+'</dt><dd>'+escape(item[0])+'</dd></div>';
        });
        html += '</dl></section></div><section class="school-widget school-online"><h3>ผู้ใช้ออนไลน์</h3><div class="school-table-scroll"><table><thead><tr><th>ชื่อผู้ใช้</th><th>IP ผู้ใช้</th><th>IP NAS / FortiGate</th><th>เริ่มเชื่อมต่อ</th><th>เวลาที่ใช้งาน</th></tr></thead><tbody>';
        var rows = sessions.items || [];
        rows.forEach(function (row) {
            var seconds = Math.max(0, Number(row.acctsessiontime) || 0);
            var updated = accountingTime(row.acctupdatetime);
            var started = accountingTime(row.acctstarttime);
            // Anchor to the last accounting update, with start time as a fallback.
            var anchor = Number.isFinite(updated) ? updated : (Number.isFinite(started) ? started : Date.now());
            if (!Number.isFinite(updated) && Number.isFinite(started)) seconds = 0;
            var startLabel = Number.isFinite(started) ? new Date(started).toLocaleString('th-TH', {
                year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:false
            }) : row.acctstarttime;
            html += '<tr><td>'+escape(row.username)+'</td><td>'+escape(row.framedipaddress || '—')+'</td><td>'+escape(row.nasipaddress || '—')+'</td><td>'+escape(startLabel)+'</td><td data-school-seconds="'+seconds+'" data-school-anchor="'+anchor+'">'+duration(seconds + Math.max(0,(Date.now()-anchor)/1000))+'</td></tr>';
        });
        if (!rows.length) html += '<tr><td colspan="5" class="school-empty">ยังไม่มีผู้ใช้ออนไลน์</td></tr>';
        node.innerHTML = html + '</tbody></table></div></section><p class="school-note">อัปเดตข้อมูลทุก 5 วินาที · ล่าสุด '+escape(new Date().toLocaleTimeString('th-TH'))+'</p>';
    }
    function populate() {
        if (window.schoolUi.enabled) {
            document.querySelectorAll('[id^="tbtext-"] h1').forEach(function (heading) {
                Array.from(heading.childNodes).forEach(function (node) {
                    if (node.nodeType === Node.TEXT_NODE && node.textContent.indexOf('RADIUSdesk') !== -1) {
                        node.textContent = node.textContent.replace('RADIUSdesk', 'โรงเรียนหนองบัวเเดงวิทยา WIFI');
                    }
                });
            });
            document.querySelectorAll('[id^="tbtext-"] img[alt="Logo"], img.school-organization-logo').forEach(function (logo) {
                var source = 'resources/images/logo-edu.jpg';
                if (logo.getAttribute('src') !== source) logo.setAttribute('src', source);
                if (!logo.classList.contains('school-organization-logo')) logo.classList.add('school-organization-logo');
                if (!logo.parentElement.classList.contains('school-brand')) logo.parentElement.classList.add('school-brand');
                if (logo.alt !== 'โลโก้โรงเรียน') logo.alt = 'โลโก้โรงเรียน';
            });
        }
        refreshDashboards(false);
    }
    function refreshDashboards(force) {
        if (!window.schoolUi.enabled || document.hidden) return;
        document.querySelectorAll('[data-school-dashboard]').forEach(function (node) {
            if (node.schoolLoading || (!force && Date.now() - (node.schoolUpdated || 0) < 5000)) return;
            node.schoolLoading = true;
            var cloud = node.dataset.schoolDashboard;
            Promise.all([
                request('dashboard/users-items',{cloud_id:cloud}),
                request('nas/index',{cloud_id:cloud,limit:1,page:1,start:0}),
                request('profiles/index',{cloud_id:cloud,limit:1,page:1,start:0}),
                request('radaccts/index',{cloud_id:cloud,only_connected:'true',limit:100,page:1,start:0,sort:'acctstarttime',dir:'DESC'})
            ]).then(function (data) {
                if (node.isConnected) render(node,data[0],data[1],data[2],data[3]);
            }).catch(function () {
                if (node.isConnected && !node.schoolUpdated) node.textContent = 'ไม่สามารถโหลด Dashboard ได้';
            }).then(function () {
                node.schoolUpdated = Date.now();
                node.schoolLoading = false;
            });
        });
    }

    function install() {
        if (!window.Ext || !Ext.Ajax || !Ext.data || !Ext.data.Store) return false;
        var originalLoad = Ext.data.Store.prototype.load;
        Ext.data.Store.prototype.load = function () {
            var store = this;
            if (!store.schoolMenuBound && ['sMainUsers','sMainOther'].indexOf(store.getStoreId()) !== -1) {
                store.schoolMenuBound = true;
                store.on('load', function () {
                    if (!window.schoolUi.enabled) return;
                    var remove = [];
                    store.each(function (record) {
                        var rows = filterRows([record.getData()]);
                        if (!rows.length) { remove.push(record); return; }
                        Object.keys(record.getData()).forEach(function (key) {
                            if (/^column/.test(key)) record.set(key, rows[0][key] || null);
                        });
                    });
                    store.remove(remove);
                });
            }
            return originalLoad.apply(this, arguments);
        };
        var refreshTimer;
        Ext.Ajax.on('requestcomplete', function (connection, response, options) {
            if (options.schoolRaw) return;
            var method = String(options.method || 'GET').toUpperCase();
            if (method === 'GET' && !/\/(add|edit|delete|remove|enable|disable)[^/]*\.json/.test(options.url || '')) return;
            try { if (JSON.parse(response.responseText).success === false) return; } catch (error) { return; }
            clearTimeout(refreshTimer);
            refreshTimer = setTimeout(function () { refreshDashboards(true); }, 300);
        });
        setInterval(function () { tickSessions(); refreshDashboards(false); }, 1000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { tickSessions(); refreshDashboards(true); }
        });
        Ext.Ajax.on('requestcomplete',function (connection, response, options) {
            if (!window.schoolUi.enabled || options.schoolRaw || !/\/dashboard\//.test(options.url)) return;
            var data;
            try { data = JSON.parse(response.responseText); } catch (error) { return; }
            if (!data.success) return;
            if (/nav-tree\.json/.test(options.url)) data.items = data.items.filter(function (item) {return item.id !== 'tabMainNetworks';});
            if (/users-items\.json|other-items\.json/.test(options.url)) data.items = filterRows(data.items);
            if (/utilities-items\.json/.test(options.url)) data.data = data.data.filter(function (item) {return ['btnDataUsage','btnTestRadius','btnRadstats','btnFreeradiusStats'].indexOf(item.itemId)!==-1;});
            if (/items-for\.json/.test(options.url)) {
                data.items = filterRows(data.items);
                var params = options.params || {};
                var cloud = params.cloud_id || Ext.getApplication().getCloudId();
                if (params.item_id === 'tabMainOverview' && cloud) data.items.unshift({xtype:'panel',title:'Dashboard',id:'schoolSummary',scrollable:true,border:false,html:'<div class="school-dashboard" data-school-dashboard="'+escape(cloud)+'">กำลังโหลด…</div>'});
            }
            // Client presentation adapter only; the server and HTTP payload are unchanged.
            response.responseText = JSON.stringify(data);
        });
        new MutationObserver(populate).observe(document.documentElement,{childList:true,subtree:true});
        populate();
        return true;
    }
    if (!install()) { var timer = setInterval(function () {if (install()) clearInterval(timer);},10); }
}());
