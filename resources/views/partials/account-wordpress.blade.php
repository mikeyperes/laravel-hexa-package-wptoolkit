{{--
/**
 * WordPress Installations Partial
 * ================================
 * Reusable collapsible section showing WordPress installations for a hosting account.
 * Provides scan, credential lookup, auto-login, and password reset functionality.
 *
 * @param \hexa_package_whm\Models\WhmServer $server  The WHM server instance
 * @param \hexa_package_whm\Models\HostingAccount $account  The hosting account instance
 *
 * Usage:
 *   @include('wptoolkit::partials.account-wordpress', ['server' => $server, 'account' => $account])
 */
--}}

@if(Route::has('wptoolkit.get-installs'))
@push('scripts')
<script>
document.addEventListener('alpine:init', function() {
    if (Alpine.components && Alpine.components.wpToolkit) return;
    Alpine.data('wpToolkit', function(cfg) {
        return {
            wpOpen: true, wpLoading: false, wpInstalls: null, wpCredentials: {}, wpCredLoading: {},
            wpAutoLogging: {}, wpPasswordVisible: {}, wpResetForm: null, wpResetResult: null,
            wpScanError: null, wpUserFilter: '', wpCopyDone: {},
            _h() { return {'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'}; },
            wpScan() {
                this.wpLoading = true; this.wpScanError = null;
                fetch(cfg.routes.getInstalls, {method:'POST',headers:this._h(),body:JSON.stringify({server_id:cfg.serverId,username:cfg.username})})
                .then(r=>r.json()).then(d=>{this.wpInstalls=d.installs||[];this.wpLoading=false;this.wpOpen=true;(this.wpInstalls||[]).forEach(wp=>this.wpFetchCreds(wp));})
                .catch(e=>{this.wpLoading=false;this.wpScanError='Failed to scan: '+(e.message||'Unknown error');});
            },
            wpFetchCreds(wp) {
                this.wpCredLoading[wp.path]=true;
                fetch(cfg.routes.getCredentials, {method:'POST',headers:this._h(),body:JSON.stringify({server_id:cfg.serverId,install_id:wp.id,wp_path:wp.path,username:cfg.username,login_url:wp.url})})
                .then(r=>r.json()).then(d=>{this.wpCredentials[wp.path]=d;this.wpCredLoading[wp.path]=false;})
                .catch(e=>{this.wpCredentials[wp.path]={error:e.message||'Failed'};this.wpCredLoading[wp.path]=false;});
            },
            wpAutoLogin(wp, wpUser) {
                var key=wp.path+'::'+wpUser; this.wpAutoLogging[key]=true;
                fetch(cfg.routes.wpLogin, {method:'POST',headers:this._h(),body:JSON.stringify({server_id:cfg.serverId,wp_path:wp.path,username:cfg.username,wp_user:wpUser,site_url:wp.url})})
                .then(r=>r.json()).then(d=>{this.wpAutoLogging[key]=false;if(d.url)window.open(d.url,'_blank');else alert('Auto-login failed: '+(d.error||'no URL'));})
                .catch(e=>{this.wpAutoLogging[key]=false;alert('Auto-login failed: '+(e.message||'Unknown error'));});
            },
            wpBestLoginUser(wp) {
                var users=this.wpCredentials[wp.path]?.admin_users||[];
                for(var i=0;i<users.length;i++){if(users[i].is_default_login)return users[i].user_login||users[i].username;}
                if(this.wpCredentials[wp.path]?.stored_credentials?.username)return this.wpCredentials[wp.path].stored_credentials.username;
                return wp.admin_user||'admin';
            },
            wpIsLogging(wp, wpUser) { return this.wpAutoLogging[wp.path+'::'+wpUser]===true; },
            wpOpenReset(wp, user) {
                this.wpResetForm={path:wp.path,installId:wp.id,wpPath:wp.path,wpUrl:wp.url,username:user.username||user.user_login||'admin',email:user.email||user.user_email||'',password:'',show:true,saving:false};
                this.wpResetResult=null;
            },
            wpGeneratePassword() {
                var chars='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*',pw='';
                for(var i=0;i<24;i++)pw+=chars.charAt(Math.floor(Math.random()*chars.length));this.wpResetForm.password=pw;
            },
            wpDoReset() {
                if(!this.wpResetForm.password)return;this.wpResetForm.saving=true;this.wpResetResult=null;
                fetch(cfg.routes.resetPassword, {method:'POST',headers:this._h(),body:JSON.stringify({server_id:cfg.serverId,install_id:this.wpResetForm.installId,wp_path:this.wpResetForm.wpPath,username:cfg.username,wp_user:this.wpResetForm.username})})
                .then(r=>r.json()).then(d=>{this.wpResetForm.saving=false;if(d.password){this.wpResetResult={success:true,password:d.password};}else{this.wpResetResult={success:false,message:d.error||d.message||'Failed'};}})
                .catch(e=>{this.wpResetForm.saving=false;this.wpResetResult={success:false,message:'Request failed: '+(e.message||'Unknown error')};});
            },
            wpUserMatches(user) {
                if(!this.wpUserFilter)return true;var q=this.wpUserFilter.toLowerCase();
                return(user.username||user.user_login||'').toLowerCase().includes(q)||(user.email||user.user_email||'').toLowerCase().includes(q)||(user.display_name||'').toLowerCase().includes(q);
            },
            wpDoCopy(text, doneKey) {
                var self = this;
                try {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                } catch(e) {}
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).catch(function(){});
                }
                self.wpCopyDone[doneKey] = true;
                setTimeout(function() { self.wpCopyDone[doneKey] = false; }, 2000);
            },
            wpCopyPw(wp) {
                var du = (this.wpCredentials[wp.path]?.admin_users || []).find(function(u) { return u.is_default_login; });
                if (du && du.stored_password) this.wpDoCopy(du.stored_password, wp.path + '_pw');
            },
            wpCopyLogin(wp) {
                var du = (this.wpCredentials[wp.path]?.admin_users || []).find(function(u) { return u.is_default_login; });
                if (!du) return;
                var info = 'Login URL: ' + (wp.login_url || (wp.url + '/wp-login.php')) + '\nUsername: ' + (du.user_login || du.username) + '\nPassword: ' + (du.stored_password || 'N/A');
                this.wpDoCopy(info, wp.path + '_login');
            },
            wpCopyUserPw(wp, user) {
                this.wpDoCopy(user.stored_password, wp.path + '-' + (user.user_login || user.username) + '_pw');
            },
            wpCopyUserLogin(wp, user) {
                var info = 'Login URL: ' + (wp.login_url || (wp.url + '/wp-login.php')) + '\nUsername: ' + (user.user_login || user.username) + '\nPassword: ' + (user.stored_password || 'N/A');
                this.wpDoCopy(info, wp.path + '-' + (user.user_login || user.username) + '_login');
            },
            wpFormatDate(s) {if(!s)return'-';try{var d=new Date(s+(s.includes('T')?'':'Z'));return d.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'})+' '+d.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'});}catch(e){return s;}}
        };
    });
});
</script>
@endpush

<div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6"
     x-data="wpToolkit({
        serverId: {{ $server->id }},
        username: '{{ $account->username }}',
        routes: {
            getInstalls: '{{ route('wptoolkit.get-installs') }}',
            getCredentials: '{{ route('wptoolkit.get-credentials') }}',
            wpLogin: '{{ route('wptoolkit.wp-login') }}',
            resetPassword: '{{ route('wptoolkit.reset-password') }}'
        }
     })">

    {{-- Section Header (entire header is clickable for expand/collapse) --}}
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between cursor-pointer select-none" @click="wpOpen = !wpOpen">
        <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
            WordPress Installations
            <template x-if="wpInstalls !== null">
                <span class="text-xs font-normal text-gray-400" x-text="wpInstalls.length + ' found'"></span>
            </template>
        </h2>
        <div class="flex items-center gap-3">
            {{-- Scan button (stop propagation so it doesn't toggle collapse) --}}
            <button @click.stop="wpScan()" :disabled="wpLoading"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-blue-600 bg-blue-50 rounded-lg hover:bg-blue-100 border border-blue-200 disabled:opacity-50">
                <svg x-show="!wpLoading" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <svg x-show="wpLoading" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                <span x-text="wpLoading ? 'Scanning...' : 'Scan'"></span>
            </button>
            {{-- Chevron --}}
            <svg class="w-4 h-4 text-gray-400 transition-transform" :class="wpOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </div>
    </div>

    {{-- Section Body --}}
    <div x-show="wpOpen" x-collapse>
        <div class="p-6">

            {{-- Scan Error Banner --}}
            <template x-if="wpScanError">
                <div class="rounded-lg px-4 py-3 text-sm font-medium bg-red-50 border border-red-200 text-red-800 mb-4">
                    <span x-text="wpScanError"></span>
                </div>
            </template>

            {{-- Empty state: no installs found --}}
            <template x-if="wpInstalls !== null && wpInstalls.length === 0">
                <div class="text-center py-8">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                    <p class="text-sm text-gray-500">No WordPress installations found.</p>
                </div>
            </template>

            {{-- Not scanned yet --}}
            <template x-if="wpInstalls === null && !wpLoading">
                <div class="text-center py-8">
                    <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <p class="text-sm text-gray-500">Click "Scan" to detect WordPress installations on this account.</p>
                </div>
            </template>

            {{-- Loading spinner --}}
            <div x-show="wpLoading" class="flex items-center justify-center gap-2 py-8 text-sm text-gray-500">
                <svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                Scanning for WordPress installations...
            </div>

            {{-- Install Cards --}}
            <template x-if="wpInstalls !== null && wpInstalls.length > 0">
                <div class="grid grid-cols-1 gap-4">
                    <template x-for="(wp, wpIdx) in wpInstalls" :key="wp.path || wpIdx">
                        <div class="border border-gray-200 rounded-xl p-5 hover:border-blue-200 transition-colors">

                            {{-- Card Header: Site URL + Version Badge --}}
                            <div class="flex items-start justify-between mb-3">
                                <div class="min-w-0 flex-1">
                                    <a :href="wp.url" target="_blank" class="text-sm font-semibold text-blue-600 hover:text-blue-800 hover:underline break-words inline-flex items-center gap-1">
                                        <span x-text="wp.url"></span>
                                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    </a>
                                    <p class="text-xs text-gray-400 font-mono mt-0.5 break-words" x-text="wp.relative_path || wp.path"></p>
                                </div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600 shrink-0 ml-3" x-text="'WP ' + (wp.version || '?')"></span>
                            </div>

                            {{-- Row 1: Admin user + badge --}}
                            <div class="flex flex-wrap items-center gap-3 mb-1 text-xs text-gray-500">
                                <template x-if="wp.admin_user || wpCredentials[wp.path]?.stored_credentials?.username">
                                    <span class="inline-flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        <span class="font-mono text-gray-700" x-text="wp.admin_user || wpCredentials[wp.path]?.stored_credentials?.username"></span>
                                    </span>
                                </template>
                                <template x-if="wpCredentials[wp.path] && !wpCredentials[wp.path].error">
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs font-medium bg-green-50 text-green-700 border border-green-200">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span x-text="(wpCredentials[wp.path].admin_users || []).length + ' users'"></span>
                                    </span>
                                </template>
                                <template x-if="wpCredentials[wp.path] && wpCredentials[wp.path].error">
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700 border border-red-200">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        No credentials
                                    </span>
                                </template>
                            </div>

                            {{-- Row 2: Password (masked, click to show) + Copy Password + Copy Login Info --}}
                            <template x-if="(wpCredentials[wp.path]?.admin_users || []).find(u => u.is_default_login && u.stored_password)">
                                <div class="flex flex-wrap items-center gap-2 mb-3 text-xs">
                                    <span class="inline-flex items-center gap-1 text-gray-500" x-data="{show:false}">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                        <span class="font-mono text-gray-700 cursor-pointer hover:text-gray-900" @click="show=!show"
                                            x-text="show ? ((wpCredentials[wp.path]?.admin_users || []).find(u => u.is_default_login) || {}).stored_password || '' : String.fromCharCode(8226).repeat(8)"></span>
                                    </span>
                                    <button @click="wpCopyPw(wp)"
                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded border transition-colors"
                                        :class="wpCopyDone[wp.path+'_pw'] ? 'text-green-700 bg-green-50 border-green-300' : 'text-blue-600 bg-blue-50 border-blue-200 hover:bg-blue-100'">
                                        <svg x-show="!wpCopyDone[wp.path+'_pw']" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        <svg x-show="wpCopyDone[wp.path+'_pw']" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span x-text="wpCopyDone[wp.path+'_pw'] ? 'Password Copied!' : 'Copy Password'"></span>
                                    </button>
                                    <button @click="wpCopyLogin(wp)"
                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded border transition-colors"
                                        :class="wpCopyDone[wp.path+'_login'] ? 'text-green-700 bg-green-50 border-green-300' : 'text-purple-600 bg-purple-50 border-purple-200 hover:bg-purple-100'">
                                        <svg x-show="!wpCopyDone[wp.path+'_login']" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        <svg x-show="wpCopyDone[wp.path+'_login']" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        <span x-text="wpCopyDone[wp.path+'_login'] ? 'Login Info Copied!' : 'Copy Login Info'"></span>
                                    </button>
                                </div>
                            </template>

                            {{-- Login URL (from scan data — always available immediately) --}}
                            <template x-if="wp.login_url">
                                <div class="flex flex-wrap items-center gap-3 mb-3 text-xs">
                                    <a :href="wp.login_url" target="_blank" class="text-gray-500 hover:text-blue-600 hover:underline inline-flex items-center gap-1 break-words">
                                        <span x-text="wp.login_url"></span>
                                        <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    </a>
                                    <template x-if="wp.login_url && !wp.login_url.includes('/wp-login.php')">
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold bg-orange-100 text-orange-700">Custom login URL</span>
                                    </template>
                                </div>
                            </template>

                            {{-- Action Buttons --}}
                            <div class="flex flex-wrap items-center gap-2 mb-3">
                                {{-- Show Credentials (only visible before credentials are loaded) --}}
                                <button @click="wpFetchCreds(wp)" :disabled="wpCredLoading[wp.path] === true"
                                    x-show="!wpCredentials[wp.path]"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-gray-700 bg-gray-50 rounded-lg hover:bg-gray-100 border border-gray-200 disabled:opacity-50">
                                    <svg x-show="!wpCredLoading[wp.path]" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <svg x-show="wpCredLoading[wp.path]" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="wpCredLoading[wp.path] ? 'Loading...' : 'Show Credentials'"></span>
                                </button>

                                {{-- Auto Login (uses best known username) --}}
                                <button @click="wpAutoLogin(wp, wpBestLoginUser(wp))" :disabled="wpIsLogging(wp, wpBestLoginUser(wp))"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 rounded-lg hover:bg-green-100 border border-green-200 disabled:opacity-50">
                                    <svg x-show="!wpIsLogging(wp, wpBestLoginUser(wp))" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                    <svg x-show="wpIsLogging(wp, wpBestLoginUser(wp))" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <span x-text="wpIsLogging(wp, wpBestLoginUser(wp)) ? 'Logging in...' : 'Auto Login'"></span>
                                </button>
                            </div>

                            {{-- User Filter (shown only when credentials are loaded and there are users) --}}
                            <template x-if="wpCredentials[wp.path] && !wpCredentials[wp.path].error && ((wpCredentials[wp.path].admin_users && wpCredentials[wp.path].admin_users.length > 1) || (wpCredentials[wp.path].credentials && wpCredentials[wp.path].credentials.users && wpCredentials[wp.path].credentials.users.length > 1))">
                                <div class="mb-3">
                                    <input type="text" x-model="wpUserFilter" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-form-type="other"
                                        placeholder="Filter users by username or email..."
                                        class="w-full px-3 py-1.5 text-xs border border-gray-200 rounded-lg focus:border-blue-400 focus:ring-1 focus:ring-blue-400 placeholder-gray-400">
                                </div>
                            </template>

                            {{-- Credentials Table (shown after fetch, displays users with password, role, actions) --}}
                            <template x-if="wpCredentials[wp.path] && !wpCredentials[wp.path].error && ((wpCredentials[wp.path].admin_users && wpCredentials[wp.path].admin_users.length > 0) || (wpCredentials[wp.path].credentials && wpCredentials[wp.path].credentials.users && wpCredentials[wp.path].credentials.users.length > 0))">
                                <div class="mt-3 border border-gray-100 rounded-lg overflow-hidden">
                                    <table class="w-full text-xs">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Username</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Role</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Password</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Registered</th>
                                                <th class="text-left px-3 py-2 text-gray-500 font-medium">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="(user, uIdx) in (wpCredentials[wp.path].admin_users || wpCredentials[wp.path].credentials?.users || [])" :key="user.username || user.user_login || uIdx">
                                                <tr class="border-t border-gray-100" x-show="wpUserMatches(user)">
                                                    {{-- Username + Email below + default marker --}}
                                                    <td class="px-3 py-2">
                                                        <div class="flex items-center gap-1.5">
                                                            <span class="font-mono text-gray-900 break-words" x-text="user.username || user.user_login"></span>
                                                            <template x-if="user.is_default_login">
                                                                <span class="inline-block px-1.5 py-0.5 text-[10px] font-semibold bg-blue-100 text-blue-700 rounded">DEFAULT</span>
                                                            </template>
                                                        </div>
                                                        <div class="text-gray-400 break-words mt-0.5" x-text="user.email || user.user_email || '-'"></div>
                                                    </td>

                                                    {{-- Role --}}
                                                    <td class="px-3 py-2 text-gray-600" x-text="user.role || user.roles || '-'"></td>

                                                    {{-- Password (masked + eye + copy icons) --}}
                                                    <td class="px-3 py-2">
                                                        <template x-if="user.stored_password">
                                                            <div class="flex items-center gap-1">
                                                                <span class="font-mono text-gray-700 break-words"
                                                                    x-text="wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)]
                                                                        ? user.stored_password
                                                                        : String.fromCharCode(8226).repeat(8)"></span>
                                                                <button @click="wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)] = !wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)]"
                                                                    class="text-gray-400 hover:text-gray-600 shrink-0 p-0.5" title="Show/hide password">
                                                                    <svg x-show="!wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)]" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                                    <svg x-show="wpPasswordVisible[wp.path + '-' + (user.username || user.user_login)]" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                                                                </button>
                                                            </div>
                                                            <div class="flex items-center gap-1.5 mt-1">
                                                                <button @click="wpCopyUserPw(wp, user)"
                                                                    class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] font-medium rounded border transition-colors"
                                                                    :class="wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_pw'] ? 'text-green-700 bg-green-50 border-green-300' : 'text-gray-500 bg-gray-50 border-gray-200 hover:bg-gray-100'">
                                                                    <svg x-show="!wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_pw']" class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                                    <svg x-show="wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_pw']" class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                                    <span x-text="wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_pw'] ? 'Copied!' : 'Copy PW'"></span>
                                                                </button>
                                                                <button @click="wpCopyUserLogin(wp, user)"
                                                                    class="inline-flex items-center gap-0.5 px-1.5 py-0.5 text-[10px] font-medium rounded border transition-colors"
                                                                    :class="wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_login'] ? 'text-green-700 bg-green-50 border-green-300' : 'text-gray-500 bg-gray-50 border-gray-200 hover:bg-gray-100'">
                                                                    <svg x-show="!wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_login']" class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                                                    <svg x-show="wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_login']" class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                                    <span x-text="wpCopyDone[wp.path+'-'+(user.user_login||user.username)+'_login'] ? 'Copied!' : 'Copy Login'"></span>
                                                                </button>
                                                            </div>
                                                        </template>
                                                        <template x-if="!user.stored_password">
                                                            <span class="text-gray-400 text-xs">-</span>
                                                        </template>
                                                    </td>

                                                    {{-- Registered Date --}}
                                                    <td class="px-3 py-2 text-gray-500 whitespace-nowrap" x-text="wpFormatDate(user.user_registered)"></td>

                                                    {{-- Actions (Set PW + Login only) --}}
                                                    <td class="px-3 py-2">
                                                        <div class="flex items-center gap-1.5">
                                                            <button @click="wpOpenReset(wp, user)"
                                                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-orange-600 bg-orange-50 rounded hover:bg-orange-100 border border-orange-200">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                                                Set PW
                                                            </button>
                                                            <button @click="wpAutoLogin(wp, user.username || user.user_login)" :disabled="wpIsLogging(wp, user.username || user.user_login)"
                                                                class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-green-600 bg-green-50 rounded hover:bg-green-100 border border-green-200 disabled:opacity-50">
                                                                <svg x-show="!wpIsLogging(wp, user.username || user.user_login)" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                                                                <svg x-show="wpIsLogging(wp, user.username || user.user_login)" class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                                                Login
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>

                            {{-- Credential fetch error --}}
                            <template x-if="wpCredentials[wp.path] && wpCredentials[wp.path].error">
                                <div class="mt-3 rounded-lg px-4 py-3 text-xs font-medium bg-red-50 border border-red-200 text-red-700">
                                    <span x-text="wpCredentials[wp.path].error"></span>
                                </div>
                            </template>

                        </div>
                    </template>
                </div>
            </template>

            {{-- Password Reset Modal --}}
            <template x-if="wpResetForm && wpResetForm.show">
                <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="wpResetForm.show = false">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 p-6">
                        <h3 class="text-base font-semibold text-gray-900 mb-4 flex items-center gap-2">
                            <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                            Set WordPress Password
                        </h3>

                        <div class="space-y-3 mb-4">
                            {{-- Username (read-only display) --}}
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Username</label>
                                <p class="text-sm font-mono text-gray-900 break-words" x-text="wpResetForm.username"></p>
                            </div>

                            {{-- Email (read-only display) --}}
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
                                <p class="text-sm text-gray-900 break-words" x-text="wpResetForm.email || 'N/A'"></p>
                            </div>

                            {{-- New Password input with Generate button --}}
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1">New Password</label>
                                <div class="flex items-center gap-2">
                                    <input type="text" x-model="wpResetForm.password" autocomplete="one-time-code" data-1p-ignore data-lpignore="true" data-form-type="other"
                                        class="flex-1 px-3 py-2 text-sm font-mono border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-1 focus:ring-blue-500 break-words"
                                        placeholder="Enter or generate password">
                                    <button @click="wpGeneratePassword()"
                                        class="inline-flex items-center gap-1 px-3 py-2 text-xs font-medium text-purple-600 bg-purple-50 rounded-lg hover:bg-purple-100 border border-purple-200 shrink-0">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Generate
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Reset Result Banner --}}
                        <template x-if="wpResetResult">
                            <div class="mb-4">
                                <div class="rounded-lg px-4 py-3 text-sm font-medium"
                                    :class="wpResetResult.success ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-red-50 border border-red-200 text-red-800'">
                                    <template x-if="wpResetResult.success">
                                        <div>
                                            <p class="mb-2">Password reset successfully!</p>
                                            <p class="font-mono text-sm bg-white border rounded px-3 py-2 text-gray-900 break-all" x-text="wpResetResult.password"></p>
                                            <div class="flex gap-2 mt-3">
                                                <button @click="navigator.clipboard.writeText(wpResetResult.password)" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 border border-blue-200">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                    Copy Password
                                                </button>
                                                <button @click="
                                                    var loginUrl = wpResetForm.wpUrl ? wpResetForm.wpUrl + '/wp-login.php' : 'N/A';
                                                    var info = 'Login URL: ' + loginUrl + '\nUsername: ' + wpResetForm.username + '\nPassword: ' + wpResetResult.password;
                                                    navigator.clipboard.writeText(info);
                                                " class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-purple-700 bg-purple-50 rounded hover:bg-purple-100 border border-purple-200">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                                    Copy All Login Info
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                    <template x-if="!wpResetResult.success">
                                        <span x-text="wpResetResult.message"></span>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- Modal Footer Buttons --}}
                        <div class="flex items-center justify-end gap-3">
                            <button @click="wpResetForm.show = false"
                                class="px-4 py-2 text-xs font-medium text-gray-600 bg-gray-100 rounded-lg hover:bg-gray-200">
                                Close
                            </button>
                            <button @click="wpDoReset()" :disabled="wpResetForm.saving || !wpResetForm.password"
                                class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-medium text-white bg-orange-600 rounded-lg hover:bg-orange-700 disabled:opacity-50">
                                <svg x-show="wpResetForm.saving" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span x-text="wpResetForm.saving ? 'Resetting...' : 'Reset Password'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </template>

        </div>
    </div>
</div>
@endif
