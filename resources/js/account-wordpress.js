window.wpToolkitConfig = function (id) {
    const configNode = document.getElementById(id);
    return configNode ? JSON.parse(configNode.textContent) : {};
};

document.addEventListener('alpine:init', function() {
    if (Alpine.components && Alpine.components.wpToolkit) return;
    Alpine.data('wpToolkit', function(cfg) {
        return {
            wpOpen: true, wpLoading: false, wpInstalls: null, wpCredentials: {}, wpCredLoading: {},
            wpAutoLogging: {}, wpPasswordVisible: {}, wpResetForm: null, wpResetResult: null,
            wpScanError: null, wpUserFilter: '', wpCopyDone: {}, wpLoginUrls: {}, wpTestLog: [], wpTestRunning: false,
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
                var key=wp.path+'::'+wpUser;
                this.wpAutoLogging[key]=true;
                this.wpLoginUrls[key]=null;
                fetch(cfg.routes.wpLogin, {method:'POST',headers:this._h(),body:JSON.stringify({server_id:cfg.serverId,wp_path:wp.path,username:cfg.username,wp_user:wpUser,site_url:wp.url})})
                .then(r=>r.json()).then(d=>{
                    this.wpAutoLogging[key]=false;
                    if(d.url){
                        this.wpLoginUrls[key]=d.url;
                        var w=window.open(d.url,'_blank');
                        if(!w){this.wpLoginUrls[key+'_blocked']=true;}
                    } else {
                        this.wpLoginUrls[key]='ERROR: '+(d.error||'no URL returned');
                    }
                }).catch(e=>{
                    this.wpAutoLogging[key]=false;
                    this.wpLoginUrls[key]='ERROR: '+(e.message||'Unknown error');
                });
            },
            wpHasLoginUser(wp) {
                var users=this.wpCredentials[wp.path]?.admin_users||[];
                for(var i=0;i<users.length;i++){if(users[i].is_default_login)return true;}
                if(this.wpCredentials[wp.path]?.stored_credentials?.username)return true;
                return false;
            },
            wpBestLoginUser(wp) {
                var users=this.wpCredentials[wp.path]?.admin_users||[];
                for(var i=0;i<users.length;i++){if(users[i].is_default_login)return users[i].user_login||users[i].username;}
                if(this.wpCredentials[wp.path]?.stored_credentials?.username)return this.wpCredentials[wp.path].stored_credentials.username;
                return null;
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
                fetch(cfg.routes.resetPassword, {method:'POST',headers:this._h(),body:JSON.stringify({server_id:cfg.serverId,install_id:this.wpResetForm.installId,wp_path:this.wpResetForm.wpPath,username:cfg.username,wp_user:this.wpResetForm.username,password:this.wpResetForm.password})})
                .then(function(r){if(!r.ok){return r.text().then(function(t){try{var j=JSON.parse(t);throw new Error(j.error||j.message||'Server error ('+r.status+')');}catch(pe){if(pe.message&&!pe.message.includes('JSON'))throw pe;throw new Error('Server error ('+r.status+'): '+t.substring(0,200));}});}return r.json();})
                .then(d=>{
                    this.wpResetForm.saving=false;
                    if(d.password){
                        this.wpResetResult={success:true,password:d.password};
                        // Re-fetch credentials from server (SQLite is now updated with new password)
                        var self=this;var path=this.wpResetForm.path;
                        var wp=null;if(this.wpInstalls){for(var i=0;i<this.wpInstalls.length;i++){if(this.wpInstalls[i].path===path){wp=this.wpInstalls[i];break;}}}
                        if(wp){self.wpFetchCreds(wp);}
                    }else{this.wpResetResult={success:false,message:d.error||d.message||'Failed'};}
                })
                .catch(e=>{this.wpResetForm.saving=false;this.wpResetResult={success:false,message:'Request failed: '+(e.message||'Unknown error')};});
            },
            wpUserMatches(user) {
                if(!this.wpUserFilter)return true;var q=this.wpUserFilter.toLowerCase();
                return(user.username||user.user_login||'').toLowerCase().includes(q)||(user.email||user.user_email||'').toLowerCase().includes(q)||(user.display_name||'').toLowerCase().includes(q);
            },
            wpDoCopy(text, doneKey) {
                var self = this;
                navigator.clipboard.writeText(text);
                self.wpCopyDone[doneKey] = true;
                setTimeout(function() { self.wpCopyDone[doneKey] = false; }, 2000);
            },
            wpCopyResetLogin() {
                var loginUrl = this.wpResetForm.wpUrl ? this.wpResetForm.wpUrl + '/wp-login.php' : 'N/A';
                var info = 'Login URL: ' + loginUrl + '\nUsername: ' + this.wpResetForm.username + '\nPassword: ' + this.wpResetResult.password;
                this.wpDoCopy(info, 'reset_all');
            },
            wpTestLogin() {
                var pw = this.wpResetResult ? this.wpResetResult.password : this.wpResetForm.password;
                if(!pw)return;
                this.wpTestRunning=true;
                var now=function(){return new Date().toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit',second:'2-digit'});};
                this.wpTestLog=[{time:now(),message:'Sending test request...',status:'info'}];
                fetch(cfg.routes.testLogin, {method:'POST',headers:this._h(),body:JSON.stringify({server_id:cfg.serverId,install_id:this.wpResetForm.installId,wp_path:this.wpResetForm.wpPath,username:cfg.username,wp_user:this.wpResetForm.username,password:pw})})
                .then(function(r){if(!r.ok){return r.text().then(function(t){try{var j=JSON.parse(t);throw new Error(j.error||j.message||'Server error ('+r.status+')');}catch(pe){if(pe.message&&!pe.message.includes('JSON'))throw pe;throw new Error('Server error ('+r.status+'): '+t.substring(0,200));}});}return r.json();})
                .then(d=>{this.wpTestRunning=false;if(d.steps){this.wpTestLog=d.steps;}if(!d.steps||d.steps.length===0){this.wpTestLog.push({time:now(),message:d.error||'Unknown result',status:d.success?'ok':'error'});}})
                .catch(e=>{this.wpTestRunning=false;this.wpTestLog.push({time:now(),message:'Request failed: '+(e.message||'Unknown error'),status:'error'});});
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
            wpFormatDate(s) {if(!s)return'-';try{var d=new Date(s+(s.includes('T')?'':'Z'));return d.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'})+' '+d.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit',timeZoneName:'short'});}catch(e){return s;}}
        };
    });
});
