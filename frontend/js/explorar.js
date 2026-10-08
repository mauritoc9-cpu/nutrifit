/* Leaflet se carga al entrar; no se guardan ubicaciones en storage ni perfiles. */
const Explorar = (() => {
  const categorias = {gimnasios:['Gimnasios','dumbbell'], comida:['Comida','utensils'], suplementos:['Suplementos','heart-pulse'], todos:['Todos','search']};
  let map, layer, originLayer, library, controller, zoneTimer, lastAction, sequence=0, geoSequence=0;
  let centro=null, origenGPS=false, lugares=[], categoria='gimnasios', seleccion=null, locationState='no_solicitada', busy=false;
  const $ = id => document.getElementById('explorar-'+id);
  const active = () => document.getElementById('view-explorar')?.classList.contains('active');
  const texto = (id,value) => { if ($(id)) $(id).textContent=value; };
  function estado(message) { texto('estado',message); }
  async function request(body, signal) {
    let r;
    try {r=await fetch(API_BASE+'/explorar/lugares.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body),signal});}
    catch(e){if(e.name==='AbortError')throw e;throw Error('No pudimos conectar con el servicio de lugares. Revisá tu conexión.');}
    let json; try { json=await r.json(); } catch { throw Error('No pudimos conectar con el servicio de lugares.'); }
    if (!json.success) throw Error(json.message || 'No pudimos completar la búsqueda.');
    return json.data;
  }
  function cargarLeaflet() {
    if (window.L) return Promise.resolve();
    if (library) return library;
    library=new Promise((resolve,reject)=>{
      const css=document.createElement('link'); css.rel='stylesheet'; css.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; css.integrity='sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY='; css.crossOrigin='anonymous'; document.head.append(css);
      const js=document.createElement('script'); js.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; js.integrity='sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo='; js.crossOrigin='anonymous';
      const timer=setTimeout(()=>{js.remove();reject(Error('No se pudo cargar el mapa. Revisá tu conexión y reintentá.'));},15000);
      js.onload=()=>{clearTimeout(timer);resolve();}; js.onerror=()=>{clearTimeout(timer);js.remove();css.remove();reject(Error('No se pudo cargar el mapa. Revisá tu conexión y reintentá.'));};document.head.append(js);
    }).catch(e=>{library=null;throw e;});
    return library;
  }
  async function render() {
    const view=document.getElementById('view-explorar');
    if (!$('mapa')) {
      view.innerHTML=`<div class="explorar"><header><button type="button" class="btn btn-ghost" onclick="loadView('perfil')">Volver a Perfil</button><h1>Explorar</h1><p>Encontrá lugares que acompañen tus objetivos.</p></header>
      <form id="explorar-form" class="explorar-search"><label class="sr-only" for="explorar-query">Lugar, zona o localidad</label><input id="explorar-query" type="search" maxlength="120" placeholder="Lugar, zona o localidad" autocomplete="off"><button class="btn btn-primary" type="submit" aria-label="Buscar">${Icon('search')}<span>Buscar</span></button></form>
      <div id="explorar-zonas" class="explorar-zonas"></div>
      <div class="explorar-location"><button id="explorar-ubicacion" type="button" class="btn btn-ghost">${Icon('search')} Usar mi ubicación</button><span id="explorar-location-state">Ubicación todavía no solicitada</span></div>
      <p class="explorar-privacy">Buscamos en una zona aproximada de 3 km. No guardamos tu ubicación en tu perfil. El mapa y las búsquedas usan servicios externos de OpenStreetMap.</p>
      <div class="explorar-filtros" role="group" aria-label="Categorías">${Object.entries(categorias).map(([key,[label,icon]])=>`<button type="button" data-categoria="${key}" aria-pressed="${categoria===key}">${Icon(icon)}${label}</button>`).join('')}</div>
      <div id="explorar-estado" class="explorar-estado" role="status" aria-live="polite">Usá tu ubicación o buscá una localidad para empezar.</div>
      <div id="explorar-mapa" class="explorar-mapa" aria-label="Mapa de lugares"></div><button id="explorar-area" class="btn btn-ghost" type="button" hidden>Buscar en esta zona</button>
      <div class="explorar-results-heading"><h2 id="explorar-heading">Cerca tuyo</h2><button id="explorar-reintentar" class="btn btn-ghost" type="button" hidden>Reintentar</button></div><div id="explorar-resultados" class="explorar-resultados"></div>
      <p class="explorar-credit">Datos © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>. La cobertura depende de los datos aportados por su comunidad.</p></div>`;
      $('form').onsubmit=e=>{e.preventDefault();buscarZona();};
      $('ubicacion').onclick=ubicar;
      view.querySelectorAll('[data-categoria]').forEach(b=>b.onclick=()=>{categoria=b.dataset.categoria;view.querySelectorAll('[data-categoria]').forEach(x=>x.setAttribute('aria-pressed',String(x===b)));pintar();});
      $('area').onclick=()=>{const c=map.getCenter();centro={lat:round(c.lat),lon:round(((c.lng+180)%360+360)%360-180)};origenGPS=false;buscarCercanos();};
      $('reintentar').onclick=()=>!map?render():lastAction==='zona'?buscarZona():centro?buscarCercanos():render();
    }
    try {
      await cargarLeaflet();
      if (!active()) return;
      if (!map) {
        const configController=new AbortController();const timer=setTimeout(()=>configController.abort(),10000);
        let config;try{config=await request({accion:'config'},configController.signal);}finally{clearTimeout(timer);}
        if (!active()) return;
        if(map){map.invalidateSize();return;}
        map=L.map($('mapa'),{scrollWheelZoom:false}).setView([-34.6,-58.45],10);
        L.tileLayer(config.tiles,{maxZoom:19,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'}).on('tileerror',()=>estado('No se pudo cargar parte del mapa. Los resultados siguen disponibles; revisá tu conexión.')).addTo(map);
        layer=L.layerGroup().addTo(map); originLayer=L.layerGroup().addTo(map);
        map.on('moveend',()=>{if(centro)$('area').hidden=false;});
      }
      map.invalidateSize(); if(!lastAction)$('reintentar').hidden=true;
    } catch(e) { estado('No se pudo iniciar el mapa. Revisá tu conexión y reintentá; podés buscar una localidad igualmente.'); $('reintentar').hidden=false; }
  }
  const round=n=>Math.round(n*1000)/1000;
  function ubicar() {
    const id=++geoSequence;
    if (!navigator.geolocation || !window.isSecureContext) {locationState='no_disponible';texto('location-state','La ubicación requiere HTTPS o localhost. Podés buscar una localidad.');return;}
    locationState='solicitando';texto('location-state','Buscando tu ubicación…');$('ubicacion').disabled=true;
    navigator.geolocation.getCurrentPosition(p=>{
      if(id!==geoSequence||!active())return;
      locationState='permitida';$('ubicacion').disabled=false;texto('location-state','Ubicación aproximada disponible');
      centro={lat:round(p.coords.latitude),lon:round(p.coords.longitude)};origenGPS=true;buscarCercanos();
    },e=>{
      if(id!==geoSequence||!active())return;
      locationState=e.code===1?'denegada':e.code===2?'no_disponible':'error';$('ubicacion').disabled=false;
      texto('location-state',e.code===1?'Ubicación denegada. Podés habilitarla en tu navegador o buscar una localidad.':e.code===2?'Ubicación no disponible. Buscá una localidad o reintentá.':'No pudimos obtener tu ubicación a tiempo. Reintentá o buscá una localidad.');
    },{enableHighAccuracy:false,timeout:10000,maximumAge:60000});
  }
  async function ejecutar(body, onSuccess) {
    lastAction=body.accion;
    controller?.abort();const mine=++sequence;controller=new AbortController();const own=controller;
    const timer=setTimeout(()=>own.abort(),35000);busy=true;$('resultados').setAttribute('aria-busy','true');$('reintentar').hidden=true;
    try {const data=await request(body,own.signal);if(mine===sequence&&active()){busy=false;onSuccess(data);}}
    catch(e){if(mine===sequence&&active()){estado(e.name==='AbortError'?'La búsqueda tardó demasiado. Revisá tu conexión y reintentá.':e.message);$('reintentar').hidden=false;}}
    finally{clearTimeout(timer);if(mine===sequence){busy=false;$('resultados')?.setAttribute('aria-busy','false');}}
  }
  async function buscarZona() {
    clearTimeout(zoneTimer);
    const q=$('query').value.trim();
    if(q.length<2){estado('Escribí al menos dos caracteres para buscar.');return;}
    ++geoSequence;$('ubicacion').disabled=false;
    if(locationState==='solicitando'){locationState='no_solicitada';texto('location-state','Búsqueda de ubicación cancelada. Podés volver a intentarlo.');}
    estado('Buscando zona o lugar…');$('zonas').replaceChildren();
    await ejecutar({accion:'zona',q},data=>{
      estado(data.zonas.length?'Elegí una zona para ver lugares cercanos.':'No encontramos esa zona. Probá agregando provincia o país.');
      data.zonas.forEach(z=>{const b=document.createElement('button');b.type='button';b.className='btn btn-ghost';b.textContent=z.nombre;b.onclick=()=>{centro={lat:z.lat,lon:z.lon};origenGPS=false;$('zonas').replaceChildren();clearTimeout(zoneTimer);zoneTimer=setTimeout(buscarCercanos,1100);};$('zonas').append(b);});
    });
  }
  async function buscarCercanos() {
    if (!centro || !active())return;
    estado('Buscando lugares reales…');lugares=[];seleccion=null;pintar(false);$('area').hidden=true;
    const selected={...centro};
    await ejecutar({accion:'cercanos',...selected,categoria:'todos'},data=>{
      centro=data.centro;lugares=data.lugares;
      if(map){originLayer.clearLayers();L.circle([centro.lat,centro.lon],{radius:150,color:'#85b82a',fillOpacity:.12}).bindTooltip(origenGPS?'Tu ubicación aproximada':'Centro de la zona buscada').addTo(originLayer);map.setView([centro.lat,centro.lon],14);$('area').hidden=true;}
      pintar();
    });
  }
  function seleccionar(id, desdeMarcador=false) {
    const p=lugares.find(p=>p.id===id);if(!p)return;seleccion=id;
    document.querySelectorAll('.explorar-card').forEach(c=>c.classList.toggle('seleccionada',c.dataset.id===id));
    layer?.eachLayer(m=>{if(m.placeId===id){if(!desdeMarcador)map.setView(m.getLatLng(),16);m.openPopup();}});
    if(desdeMarcador)document.querySelector('.explorar-card.seleccionada')?.scrollIntoView({behavior:'smooth',block:'nearest'});
    else $('mapa')?.scrollIntoView({behavior:'smooth',block:'center'});
  }
  function pintar(updateStatus=true) {
    const filtered=lugares.filter(p=>categoria==='todos'||p.categoria===categoria).slice(0,30);const results=$('resultados');if(!results)return;
    results.replaceChildren();layer?.clearLayers();texto('heading',origenGPS?'Cerca tuyo':'En esta zona');
    if(updateStatus&&!busy)estado(!centro?'Usá tu ubicación o buscá una localidad para empezar.':filtered.length?`${filtered.length} ${filtered.length===1?'lugar':'lugares'} · hasta 3 km · distancias en línea recta`:'No encontramos lugares de esta categoría en la zona. Probá otra categoría o zona.');
    filtered.forEach(p=>{
      const card=document.createElement('article');card.className='explorar-card';card.dataset.id=p.id;
      const h=document.createElement('h3');h.textContent=p.nombre;const meta=document.createElement('p');meta.className='explorar-card-meta';meta.textContent=categorias[p.categoria][0]+' · '+(p.distancia_m<1000?p.distancia_m+' m':(p.distancia_m/1000).toFixed(1)+' km')+' aprox.';
      card.append(h,meta);
      for(const value of [p.direccion,p.horarios?'Horarios informados: '+p.horarios:null])if(value){const detail=document.createElement('p');detail.textContent=value;card.append(detail);}
      const actions=document.createElement('div');actions.className='explorar-card-actions';const ver=document.createElement('button');ver.type='button';ver.className='btn btn-ghost';ver.textContent='Ver en mapa';ver.onclick=()=>seleccionar(p.id);
      const route=document.createElement('a');route.className='btn btn-primary';route.textContent='Cómo llegar';route.href='https://www.google.com/maps/dir/?api=1&destination='+encodeURIComponent(p.lat+','+p.lon);route.target='_blank';route.rel='noopener noreferrer';actions.append(ver,route);card.append(actions);
      card.onclick=e=>{if(!e.target.closest('a,button'))seleccionar(p.id);};results.append(card);
      if(map){const popup=document.createElement('div');const title=document.createElement('strong');title.textContent=p.nombre;const b=document.createElement('button');b.type='button';b.textContent='Ver tarjeta';b.onclick=()=>seleccionar(p.id,true);popup.append(title,b);
        const marker=L.marker([p.lat,p.lon],{title:p.nombre,alt:p.nombre,icon:L.divIcon({className:'explorar-marker explorar-marker-'+p.categoria,html:Icon(categorias[p.categoria][1]),iconSize:[34,34],iconAnchor:[17,34]})}).bindPopup(popup,{maxWidth:230}).addTo(layer);marker.placeId=p.id;marker.on('click',()=>seleccionar(p.id,true));}
    });
  }
  function salir(){
    clearTimeout(zoneTimer);controller?.abort();++sequence;++geoSequence;
    if(busy){estado('Búsqueda cancelada. Podés volver a intentarlo.');if($('reintentar'))$('reintentar').hidden=false;}
    busy=false;$('resultados')?.setAttribute('aria-busy','false');
    if(locationState==='solicitando'){locationState='no_solicitada';texto('location-state','Ubicación todavía no solicitada');}
    if($('ubicacion'))$('ubicacion').disabled=false;
  }
  return {render,salir};
})();
