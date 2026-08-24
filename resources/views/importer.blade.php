<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Database Importer · {{ config('app.name', 'VitoDeploy') }}</title>
    <script>
        if ('{{ $appearance ?? 'system' }}' === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    </script>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet">
    <link rel="stylesheet" href="{{ route('database-importer.styles') }}">
</head>
<body class="bg-background text-foreground selection:bg-brand min-h-screen font-sans antialiased selection:text-white">
<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 lg:py-12">
    <header class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
        <div class="max-w-2xl">
            <p class="text-muted-foreground mb-2 text-sm font-medium">Database migration</p>
            <h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">Import a database</h1>
            <p class="text-muted-foreground mt-2 text-sm sm:text-base">Upload, inspect, and restore a database dump without exposing administrative credentials.</p>
        </div>
        <a href="{{ route('servers') }}" class="bg-background hover:bg-muted inline-flex h-9 items-center justify-center rounded-md border px-4 text-sm font-medium shadow-xs transition-colors">Back to servers</a>
    </header>

    <nav class="mb-8 grid grid-cols-2 overflow-hidden rounded-lg border bg-card sm:grid-cols-4" aria-label="Import progress">
        @foreach([['Upload', 'SQL dump'], ['Destination', 'Database and user'], ['Review', 'Safety checks'], ['Import', 'Progress and results']] as $index => [$title, $description])
            <div class="step-item {{ $index > 0 ? 'border-l' : '' }} {{ $index > 1 ? 'border-t sm:border-t-0' : '' }} px-4 py-3" data-step="{{ $index + 1 }}">
                <div class="flex items-baseline gap-2">
                    <span class="step-number {{ $index === 0 ? 'text-primary' : 'text-muted-foreground' }} text-xs font-semibold">{{ $index + 1 }}</span>
                    <span class="text-sm font-medium">{{ $title }}</span>
                </div>
                <p class="text-muted-foreground mt-0.5 pl-5 text-xs">{{ $description }}</p>
            </div>
        @endforeach
    </nav>

    <div class="bg-muted/30 text-muted-foreground mb-6 rounded-lg border px-4 py-3 text-sm">
        <span class="text-foreground font-medium">Native Vito restore.</span> The selected database user is linked after the import; Vito's administrative database access performs the restore.
    </div>
    <div id="message" class="mb-6 hidden rounded-lg border px-4 py-3 text-sm" role="status"></div>

    <section class="overflow-hidden rounded-xl border bg-card shadow-xs" id="upload-card">
        <div class="border-b px-5 py-4">
            <h2 class="font-semibold">Upload database dump</h2>
            <p class="text-muted-foreground mt-1 text-sm">Accepted formats: .sql, .sql.gz, or a ZIP containing exactly one .sql file.</p>
        </div>
        <div class="p-5">
            <label id="drop-zone" for="database-file" class="hover:bg-muted/30 flex min-h-52 cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed px-6 py-10 text-center transition-colors">
                <span class="bg-muted mb-4 inline-flex h-11 items-center justify-center rounded-lg px-3 text-xs font-semibold tracking-wide" aria-hidden="true">SQL</span>
                <span class="font-medium" id="file-title">Drop your database dump here</span>
                <span class="text-muted-foreground mt-1 text-sm" id="file-subtitle">or choose a file from this device</span>
                <span class="text-muted-foreground mt-3 text-xs">Up to {{ $maxUploadMb }} MB compressed · {{ $maxExtractedMb }} MB extracted</span>
                <input class="sr-only" type="file" id="database-file" accept=".sql,.sql.gz,.zip,application/sql,application/gzip,application/zip">
            </label>
            <div class="bg-muted mt-4 hidden h-2 overflow-hidden rounded-full" id="upload-progress-wrap"><span class="bg-primary block h-full w-0 transition-[width]" id="upload-progress"></span></div>
            <div class="mt-5 flex justify-end"><button id="upload-button" class="bg-primary text-primary-foreground hover:bg-primary/90 h-9 rounded-md px-4 text-sm font-medium shadow-xs transition-colors disabled:pointer-events-none disabled:opacity-50" type="button" disabled>Inspect upload</button></div>
        </div>
    </section>

    <section class="mt-6 hidden overflow-hidden rounded-xl border bg-card shadow-xs" id="destination-card">
        <div class="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div><h2 class="font-semibold">Choose the destination</h2><p class="text-muted-foreground mt-1 text-sm">Select or create the database and user Vito should manage.</p></div>
            <span class="border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-400 w-fit rounded-md border px-2.5 py-1 text-xs font-medium" id="upload-status">Upload ready</span>
        </div>
        <div class="p-5">
            <div class="bg-muted/20 mb-5 grid gap-3 rounded-lg border p-4 sm:grid-cols-3">
                <div><span class="text-muted-foreground block text-xs">File</span><strong class="mt-1 block truncate text-sm" id="summary-file"></strong></div>
                <div><span class="text-muted-foreground block text-xs">Upload / extracted</span><strong class="mt-1 block text-sm" id="summary-size"></strong></div>
                <div><span class="text-muted-foreground block text-xs">Detected engine</span><strong class="mt-1 block text-sm" id="summary-engine"></strong></div>
            </div>
            <div class="grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                <div class="space-y-2"><label class="text-sm font-medium" for="target-server">Vito server</label><select class="field" id="target-server"><option value="">Select destination</option>@foreach($servers as $server)<option value="{{ $server['id'] }}" @selected($selectedServer === $server['id'])>{{ $server['name'] }} · {{ $server['engine'] }} {{ $server['version'] }}</option>@endforeach</select></div>
                <div class="space-y-2"><label class="text-sm font-medium" for="source-engine">Source engine</label><select class="field" id="source-engine"><option value="">Confirm source engine</option><option value="mysql">MySQL</option><option value="mariadb">MariaDB</option><option value="postgresql">PostgreSQL</option></select><p class="text-muted-foreground text-xs">Confirm this when the dump is generic or detection is uncertain.</p></div>
                <div class="space-y-2"><label class="text-sm font-medium" for="target-database">Destination database</label><select class="field" id="target-database" disabled><option value="">Select a server first</option></select></div>
                <div class="hidden space-y-2" id="database-name-wrap"><label class="text-sm font-medium" for="database-name">New database name</label><input class="field" id="database-name" maxlength="64" placeholder="application_db"></div>
                <div class="space-y-2"><label class="text-sm font-medium" for="target-user">Database user</label><select class="field" id="target-user" disabled><option value="">Select a server first</option></select></div>
                <div class="hidden space-y-2" id="database-username-wrap"><label class="text-sm font-medium" for="database-username">New database username</label><input class="field" id="database-username" maxlength="64" placeholder="application_user"><p class="text-muted-foreground text-xs">Vito generates a strong password automatically.</p></div>
            </div>

            <fieldset class="mt-6 space-y-3">
                <legend class="text-sm font-medium">Existing data policy</legend>
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="hover:bg-muted/30 flex cursor-pointer items-start gap-3 rounded-lg border p-4"><input class="accent-primary mt-0.5 size-4" type="radio" name="policy" value="empty_only" checked><span><strong class="block text-sm">Require an empty database</strong><span class="text-muted-foreground mt-1 block text-xs">Stop safely if Vito finds any existing tables or objects.</span></span></label>
                    <label class="hover:bg-muted/30 flex cursor-pointer items-start gap-3 rounded-lg border p-4"><input class="accent-primary mt-0.5 size-4" type="radio" name="policy" value="overwrite"><span><strong class="block text-sm">Allow overwrite</strong><span class="text-muted-foreground mt-1 block text-xs">Clear the database before restoring the uploaded dump.</span></span></label>
                </div>
            </fieldset>
            <label class="hover:bg-muted/30 mt-4 flex cursor-pointer items-start gap-3 rounded-lg border p-4"><input class="accent-primary mt-0.5 size-4" type="checkbox" id="backup-before-overwrite" checked><span><strong class="block text-sm">Create a safety backup before overwriting</strong><span class="text-muted-foreground mt-1 block text-xs">The compressed backup remains on the destination server and its path appears in the final result.</span></span></label>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t pt-5 sm:flex-row sm:justify-end"><button type="button" class="bg-background hover:bg-muted h-9 rounded-md border px-4 text-sm font-medium shadow-xs" id="replace-upload-button">Replace upload</button><button id="review-button" class="bg-primary text-primary-foreground hover:bg-primary/90 h-9 rounded-md px-4 text-sm font-medium shadow-xs disabled:pointer-events-none disabled:opacity-50" type="button">Run safety checks</button></div>
        </div>
    </section>

    <section class="mt-6 hidden overflow-hidden rounded-xl border bg-card shadow-xs" id="review-card">
        <div class="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><div><h2 class="font-semibold">Review the import plan</h2><p class="text-muted-foreground mt-1 text-sm">Blocking checks must pass before the import can start.</p></div><span class="bg-muted text-muted-foreground w-fit rounded-md px-2.5 py-1 text-xs font-medium" id="review-badge">Safety review</span></div>
        <div class="p-5">
            <div class="grid gap-2 md:grid-cols-2" id="checks"></div>
            <div class="bg-muted/20 mt-5 rounded-lg border p-4" id="plan-summary"></div>
            <div class="border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300 mt-5 hidden rounded-lg border p-4" id="overwrite-confirmation">
                <label class="text-sm font-semibold" for="confirmation">Destructive overwrite confirmation</label>
                <p class="mt-1 text-sm">Type <strong id="confirmation-name"></strong> to confirm that its current contents may be cleared.</p>
                <input class="border-red-300 bg-background mt-3 h-9 w-full rounded-md border px-3 text-sm outline-none focus-visible:ring-3" id="confirmation" autocomplete="off">
            </div>
        </div>
        <div class="flex flex-col-reverse gap-3 border-t px-5 py-4 sm:flex-row sm:justify-end"><button type="button" class="bg-background hover:bg-muted h-9 rounded-md border px-4 text-sm font-medium shadow-xs" id="change-button">Change destination</button><button type="button" class="bg-primary text-primary-foreground hover:bg-primary/90 h-9 rounded-md px-4 text-sm font-medium shadow-xs disabled:pointer-events-none disabled:opacity-50" id="start-button">Start database import</button></div>
    </section>

    <section class="mt-6 hidden overflow-hidden rounded-xl border bg-card shadow-xs" id="run-card">
        <div class="flex items-start justify-between gap-4 border-b px-5 py-4"><div><h2 class="font-semibold">Import progress</h2><p class="text-muted-foreground mt-1 text-sm" id="run-step">Queued</p></div><span class="bg-muted text-muted-foreground rounded-md px-2.5 py-1 text-xs font-medium" id="run-status">pending</span></div>
        <div class="p-5">
            <div class="bg-muted mb-5 h-2 overflow-hidden rounded-full"><span class="bg-primary block h-full w-0 transition-[width]" id="run-progress"></span></div>
            <div class="hidden rounded-lg border p-4" id="result-card"></div>
            <div class="mt-5"><h3 class="mb-2 text-sm font-semibold">Import log</h3><div class="bg-muted/20 max-h-72 divide-y overflow-auto rounded-lg border px-4" id="run-log"><p class="text-muted-foreground py-3 text-sm">Waiting for the queue worker…</p></div></div>
        </div>
        <div class="flex flex-wrap gap-3 border-t px-5 py-4"><button type="button" class="bg-background hover:bg-muted hidden h-9 rounded-md border px-4 text-sm font-medium shadow-xs" id="retry-button">Retry failed import</button><button type="button" class="bg-background hover:bg-muted hidden h-9 rounded-md border px-4 text-sm font-medium shadow-xs" id="cancel-button">Cancel</button><button type="button" class="bg-background hover:bg-muted h-9 rounded-md border px-4 text-sm font-medium shadow-xs" id="new-button">New import</button></div>
    </section>
</main>

<script>
const CONFIG = @json($frontendConfig);
const state = {run:null, review:null, poll:null};
const $ = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const bytes = value => { const units=['B','KB','MB','GB']; let n=Number(value||0), i=0; while(n>=1024&&i<3){n/=1024;i++;} return `${n.toFixed(i?1:0)} ${units[i]}`; };
const server = () => CONFIG.servers.find(item => String(item.id) === $('target-server').value);

async function api(url, options={}) {
    const headers={'Accept':'application/json','X-CSRF-TOKEN':csrf,...(options.headers||{})};
    if(options.body && !(options.body instanceof FormData)) headers['Content-Type']='application/json';
    const response=await fetch(url,{...options,headers});
    const data=await response.json().catch(()=>({}));
    if(!response.ok){const error=new Error(data.message || Object.values(data.errors||{}).flat()[0] || `Request failed (${response.status})`);error.data=data;throw error;}
    return data;
}
function message(text,bad=false){const box=$('message');box.textContent=text;box.className=`mb-6 rounded-lg border px-4 py-3 text-sm ${bad?'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-400':'bg-muted/30 text-foreground'}`;window.scrollTo({top:0,behavior:'smooth'});setTimeout(()=>box.classList.add('hidden'),8000);}
function step(number){document.querySelectorAll('.step-number').forEach((item,index)=>{item.classList.toggle('text-primary',index<number);item.classList.toggle('text-muted-foreground',index>=number);});}
function option(value,label){return `<option value="${esc(value)}">${esc(label)}</option>`;}
function showOnly(id){['upload-card','destination-card','review-card','run-card'].forEach(card=>$(card).classList.toggle('hidden',card!==id));}

function selectedFile(){return $('database-file').files[0];}
function fileChanged(){const file=selectedFile();$('upload-button').disabled=!file;$('file-title').textContent=file?file.name:'Drop your database dump here';$('file-subtitle').textContent=file?`${bytes(file.size)} selected`:'or choose a file from this device';}
function upload(){const file=selectedFile();if(!file)return;const form=new FormData();form.append('file',file);const xhr=new XMLHttpRequest();$('upload-button').disabled=true;$('upload-button').textContent='Inspecting…';$('upload-progress-wrap').classList.remove('hidden');xhr.open('POST',CONFIG.urls.uploads);xhr.setRequestHeader('Accept','application/json');xhr.setRequestHeader('X-CSRF-TOKEN',csrf);xhr.upload.onprogress=e=>{if(e.lengthComputable)$('upload-progress').style.width=`${Math.round(e.loaded/e.total*100)}%`;};xhr.onload=()=>{let data={};try{data=JSON.parse(xhr.responseText)}catch{}if(xhr.status<200||xhr.status>=300){message(data.message||'Upload failed.',true);$('upload-button').disabled=false;$('upload-button').textContent='Inspect upload';return;}state.run=data;renderUpload();showOnly('destination-card');step(2);};xhr.onerror=()=>{message('The upload connection failed.',true);$('upload-button').disabled=false;$('upload-button').textContent='Inspect upload';};xhr.send(form);}
function renderUpload(){$('summary-file').textContent=state.run.original_name;$('summary-size').textContent=`${bytes(state.run.file_size)} / ${bytes(state.run.extracted_size)}`;$('summary-engine').textContent=state.run.detected_engine||'Needs confirmation';$('source-engine').value=state.run.detected_engine||'';if(CONFIG.selectedServer)$('target-server').value=String(CONFIG.selectedServer);syncServer();}
function syncServer(){const item=server();const db=$('target-database'),user=$('target-user');db.disabled=!item;user.disabled=!item;if(!item){db.innerHTML='<option value="">Select a server first</option>';user.innerHTML='<option value="">Select a server first</option>';return;}db.innerHTML='<option value="">Select database</option>'+item.databases.map(x=>option(`existing:${x.id}`,`${x.name} · ${x.status}`)).join('')+option('create','Create a new database');user.innerHTML='<option value="">Select database user</option>'+item.users.map(x=>option(`existing:${x.id}`,x.username)).join('')+option('create','Create a new database user');}
function syncCreateFields(){$('database-name-wrap').classList.toggle('hidden',$('target-database').value!=='create');$('database-username-wrap').classList.toggle('hidden',$('target-user').value!=='create');}
function selection(){const db=$('target-database').value,user=$('target-user').value;return {server_id:Number($('target-server').value),source_engine:$('source-engine').value,database_mode:db==='create'?'create':'existing',database_id:db.startsWith('existing:')?Number(db.split(':')[1]):null,database_name:$('database-name').value.trim()||null,user_mode:user==='create'?'create':'existing',database_user_id:user.startsWith('existing:')?Number(user.split(':')[1]):null,database_username:$('database-username').value.trim()||null,policy:document.querySelector('input[name="policy"]:checked').value,backup_before_overwrite:$('backup-before-overwrite').checked};}
async function review(){const payload=selection();if(!payload.server_id||!payload.source_engine||!$('target-database').value||!$('target-user').value)return message('Choose the server, source engine, database, and database user.',true);const button=$('review-button');button.disabled=true;button.textContent='Checking destination…';try{state.review=await api(`${CONFIG.urls.runBase}/${state.run.id}/preview`,{method:'POST',body:JSON.stringify(payload)});renderReview();showOnly('review-card');step(3);}catch(e){message(e.message,true);}finally{button.disabled=false;button.textContent='Run safety checks';}}
function renderReview(){const good=state.review.compatible;$('review-badge').textContent=good?'Checks passed':'Action required';$('review-badge').className=`w-fit rounded-md border px-2.5 py-1 text-xs font-medium ${good?'border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-400':'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-400'}`;$('checks').innerHTML=state.review.checks.map(c=>`<div class="flex items-start gap-3 rounded-lg border p-3 text-sm ${c.status==='matched'?'':'border-red-200 dark:border-red-900'}"><span class="rounded-md px-2 py-0.5 text-xs font-semibold ${c.status==='matched'?'bg-green-50 text-green-700 dark:bg-green-950/40 dark:text-green-400':'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400'}">${c.status==='matched'?'Pass':'Block'}</span><span><strong class="block font-medium">${esc(c.label)}</strong><span class="text-muted-foreground mt-0.5 block text-xs">${esc(c.value)}${!c.blocking&&c.status!=='matched'?' · confirmation required':''}</span></span></div>`).join('');const payload=selection(),item=server(),db=state.review.database_name,user=payload.user_mode==='create'?payload.database_username:item.users.find(x=>x.id===payload.database_user_id)?.username;$('plan-summary').innerHTML=`<h3 class="text-sm font-semibold">Import plan</h3><div class="text-muted-foreground mt-3 grid gap-2 text-sm sm:grid-cols-2"><p><span class="text-foreground font-medium">Destination:</span> ${esc(item.name)} / ${esc(db)}</p><p><span class="text-foreground font-medium">User:</span> ${esc(user)}</p><p><span class="text-foreground font-medium">Engine:</span> ${esc(payload.source_engine)} to ${esc(state.review.destination_engine)}</p><p><span class="text-foreground font-medium">Policy:</span> ${payload.policy==='overwrite'?'Overwrite allowed':'Empty database required'}</p></div>`;const confirm=!state.review.database_empty&&payload.policy==='overwrite';$('overwrite-confirmation').classList.toggle('hidden',!confirm);$('confirmation-name').textContent=db;$('confirmation').value='';$('start-button').disabled=!good||(!state.review.database_empty&&payload.policy!=='overwrite');}
async function start(){const button=$('start-button');button.disabled=true;button.textContent='Queueing…';try{state.run=await api(`${CONFIG.urls.runBase}/${state.run.id}/start`,{method:'POST',body:JSON.stringify({confirmation:$('confirmation').value})});showOnly('run-card');step(4);renderRun(state.run);poll();}catch(e){message(e.message,true);button.disabled=false;button.textContent='Start database import';}}
function renderRun(run){$('run-status').textContent=run.status;$('run-step').textContent=run.current_step||'';$('run-progress').style.width=`${run.progress}%`;$('retry-button').classList.toggle('hidden',run.status!=='failed');$('cancel-button').classList.toggle('hidden',!['pending','running'].includes(run.status));$('run-log').innerHTML=(run.log||[]).map(entry=>`<div class="py-3 text-sm"><span class="text-muted-foreground mr-3 text-xs">${esc(new Date(entry.at).toLocaleTimeString())}</span>${esc(entry.message)}</div>`).join('')||'<p class="text-muted-foreground py-3 text-sm">Waiting for the queue worker…</p>';const result=run.result||{};$('result-card').classList.toggle('hidden',!result.database);if(result.database)$('result-card').innerHTML=`<div class="flex items-center justify-between gap-3"><h3 class="font-semibold">Import complete</h3><span class="border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-400 rounded-md border px-2.5 py-1 text-xs font-medium">Success</span></div><div class="text-muted-foreground mt-3 grid gap-2 text-sm sm:grid-cols-2"><p><span class="text-foreground font-medium">Database:</span> ${esc(result.database)}</p><p><span class="text-foreground font-medium">User:</span> ${esc(result.database_user)}</p><p><span class="text-foreground font-medium">Server:</span> ${esc(result.server)}</p><p><span class="text-foreground font-medium">Duration:</span> ${esc(result.duration_seconds)} seconds</p>${result.backup_path?`<p class="sm:col-span-2 break-all"><span class="text-foreground font-medium">Safety backup:</span> ${esc(result.backup_path)}</p>`:''}</div>`;if(run.error)message(run.error,true);}
function poll(){clearInterval(state.poll);const tick=async()=>{try{state.run=await api(`${CONFIG.urls.runBase}/${state.run.id}`);renderRun(state.run);if(['complete','failed','cancelled','expired'].includes(state.run.status))clearInterval(state.poll);}catch(e){message(e.message,true);clearInterval(state.poll);}};tick();state.poll=setInterval(tick,3000);}

$('database-file').addEventListener('change',fileChanged);$('upload-button').addEventListener('click',upload);$('target-server').addEventListener('change',syncServer);$('target-database').addEventListener('change',syncCreateFields);$('target-user').addEventListener('change',syncCreateFields);$('review-button').addEventListener('click',review);$('start-button').addEventListener('click',start);$('change-button').addEventListener('click',()=>{showOnly('destination-card');step(2);});$('replace-upload-button').addEventListener('click',()=>location.reload());$('new-button').addEventListener('click',()=>location.reload());$('retry-button').addEventListener('click',async()=>{try{state.run=await api(`${CONFIG.urls.runBase}/${state.run.id}/retry`,{method:'POST'});renderRun(state.run);poll();}catch(e){message(e.message,true);if(e.data?.needs_review){showOnly('destination-card');step(2);document.querySelector('input[name="policy"][value="overwrite"]').checked=true;}}});$('cancel-button').addEventListener('click',async()=>{try{state.run=await api(`${CONFIG.urls.runBase}/${state.run.id}/cancel`,{method:'POST'});renderRun(state.run);clearInterval(state.poll);}catch(e){message(e.message,true);}});
['dragenter','dragover'].forEach(name=>$('drop-zone').addEventListener(name,e=>{e.preventDefault();$('drop-zone').classList.add('bg-muted/30');}));['dragleave','drop'].forEach(name=>$('drop-zone').addEventListener(name,e=>{e.preventDefault();$('drop-zone').classList.remove('bg-muted/30');}));$('drop-zone').addEventListener('drop',e=>{if(e.dataTransfer.files.length){$('database-file').files=e.dataTransfer.files;fileChanged();}});
</script>
</body>
</html>
