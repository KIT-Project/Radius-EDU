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
    function bytes(value) {
        var amount = Math.max(0, Number(value) || 0);
        var units = ['B','KB','MB','GB','TB'];
        var index = 0;
        while (amount >= 1024 && index < units.length-1) { amount /= 1024; index++; }
        return amount.toLocaleString('th-TH', {maximumFractionDigits:index ? 2 : 0})+' '+units[index];
    }
    function trafficRanking(rows) {
        var users = new Map();
        (rows || []).forEach(function (row) {
            if (!row.username) return;
            var user = users.get(row.username) || {username:row.username,upload:0,download:0};
            // RADIUS input is traffic received by the NAS from the client (upload).
            user.upload += Math.max(0, Number(row.acctinputoctets) || 0);
            user.download += Math.max(0, Number(row.acctoutputoctets) || 0);
            user.total = user.upload + user.download;
            users.set(row.username,user);
        });
        return Array.from(users.values()).filter(function (user) { return user.total > 0; })
            .sort(function (a,b) { return b.total-a.total || a.username.localeCompare(b.username); });
    }
    function requestTraffic(cloud) {
        var rows = [];
        function page(offset) {
            return request('radaccts/index',{cloud_id:cloud,limit:500,page:offset/500+1,start:offset,
                sort:'radacctid',dir:'ASC'}).then(function (data) {
                rows = rows.concat(data.items || []);
                if (offset+500 < Number(data.totalCount) && (data.items || []).length) return page(offset+500);
                return {items:rows};
            });
        }
        return page(0);
    }
    function render(node, users, nas, profiles, sessions, traffic) {
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
        html += '<section class="school-widget"><h3>ผู้ใช้ที่ใช้ Traffic สูงสุด</h3><div class="school-traffic">';
        var ranking = trafficRanking(traffic && traffic.items);
        var trafficTotal = ranking.reduce(function (sum,user) { return sum+user.total; },0);
        ranking.slice(0,5).forEach(function (user,index) {
            var share = Math.round(user.total/trafficTotal*100);
            html += '<div class="school-traffic-row"><span class="school-traffic-rank">'+(index+1)+'</span><div class="school-traffic-detail"><div class="school-traffic-heading"><strong>'+escape(user.username)+'</strong><b>'+bytes(user.total)+'</b></div><div class="school-traffic-bar"><i style="width:'+share+'%"></i></div><div class="school-traffic-meta"><span>↓ '+bytes(user.download)+' · ↑ '+bytes(user.upload)+'</span><span>'+share+'%</span></div></div></div>';
        });
        if (!ranking.length) html += '<p class="school-empty">ยังไม่มีข้อมูลปริมาณการใช้งาน</p>';
        html += '</div></section></div><section class="school-widget school-online"><h3>ผู้ใช้ออนไลน์</h3><div class="school-table-scroll"><table><thead><tr><th>ชื่อผู้ใช้</th><th>IP ผู้ใช้</th><th>IP NAS / FortiGate</th><th>เริ่มเชื่อมต่อ</th><th>เวลาที่ใช้งาน</th></tr></thead><tbody>';
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
        node.innerHTML = html + '</tbody></table></div></section>';
    }
    function populate() {
        if (window.schoolUi.enabled) {
            // Header controls remain instantiated so default-cloud selection keeps working.
            Ext.ComponentQuery.query('#cmbCloud, #btnSetupWizard').forEach(function (component) {
                if (!component.hidden) component.hide();
                var separator = component.nextSibling();
                if (separator && separator.isXType('tbseparator') && !separator.hidden) separator.hide();
            });
            Ext.ComponentQuery.query('pnlDashboard > toolbar > tbseparator').forEach(function (separator) {
                if (!separator.hidden) separator.hide();
            });
            Ext.ComponentQuery.query('pnlLogin').forEach(function (panel) {
                panel.getDockedItems().forEach(function (item) {
                    if (item.dock === 'bottom' && !item.hidden) item.hide();
                });
            });
            Ext.ComponentQuery.query('pnlLogin').forEach(function (panel) {
                if (!panel.hasCls('school-login-page')) panel.addCls('school-login-page');
            });
            Ext.ComponentQuery.query('#winLogin').forEach(function (card) {
                if (card.schoolLoginStyled) return;
                var form = card.down('form');
                if (!form) return;
                card.schoolLoginStyled = true;
                card.addCls('school-login-card');
                card.setWidth(Math.min(420, Math.max(280, window.innerWidth - 32)));
                card.setHeight(530);
                form.items.getAt(0).update('<div class="school-login-intro"><img src="resources/images/logo-edu.jpg" alt="ตราโรงเรียน"><span>SMART SCHOOL</span><h2>เข้าสู่ระบบจัดการ</h2></div>');
                form.items.getAt(1).update('<p class="school-login-description">จัดการผู้ใช้และเครือข่าย Wi-Fi ของโรงเรียน</p>');
                var username = form.down('#inpUsername');
                var password = form.down('#inpPassword');
                username.setFieldLabel('ชื่อผู้ใช้');
                username.setEmptyText('ชื่อผู้ดูแลระบบ');
                password.setFieldLabel('รหัสผ่าน');
                password.setEmptyText('กรอกรหัสผ่าน');
                var button = form.down('button[type="submit"]');
                button.setText('เข้าสู่ระบบ');
                button.setWidth(card.getWidth() - 64);
                button.setMargin('16 10 0 10');
                card.updateLayout();
                card.center();
            });
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
                request('radaccts/index',{cloud_id:cloud,only_connected:'true',limit:100,page:1,start:0,sort:'acctstarttime',dir:'DESC'}),
                requestTraffic(cloud)
            ]).then(function (data) {
                if (node.isConnected) render(node,data[0],data[1],data[2],data[3],data[4]);
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
