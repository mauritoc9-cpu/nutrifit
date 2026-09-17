/**
 * NutriFit — Avatar "robot" (modelo 3D real de Meshy, GLB riggeado).
 * =====================================================================
 * Reemplaza al robot procedural anterior (geometrías primitivas) por el
 * modelo exportado de Meshy: 1 malla con esqueleto (24 joints, jerarquía
 * tipo Mixamo) + 1 material (textura 2048×2048 embebida, usada como
 * baseColor y emissive) + 11 animaciones, cada una exportada por Meshy
 * como un GLB independiente que reexporta la MISMA malla+esqueleto.
 *
 * Assets reales (ver frontend/assets/models/avatars/robot/):
 *   robot-base.glb                    → malla+esqueleto+pose de reposo
 *                                        (su único clip, "clip0", dura
 *                                        0.033s: es la pose neutral, no
 *                                        un idle real — de ahí el idle
 *                                        procedural del punto 8 del brief)
 *   animations/greeting-bow-gentleman.glb  ← clip "Armature|Gentlemans_Bow|baselayer" (7.3s)
 *   animations/greeting-bow-formal.glb     ← clip "Armature|Formal_Bow|baselayer" (7.7s)
 *   animations/success-victory.glb         ← clip "Armature|victory|baselayer" (8.6s)
 *   animations/celebration-victory-cheer.glb ← clip "Armature|Victory_Cheer|baselayer" (9.367s)
 *   animations/levelup-gangnam-groove.glb  ← clip "Armature|Gangnam_Groove|baselayer" (10.167s)
 *   animations/running.glb                 ← clip "Armature|running|baselayer" (0.667s, loop)
 *   animations/walking.glb                 ← clip "Armature|walking_man|baselayer" (1.067s, loop)
 *   animations/exercise-pushup.glb         ← clip "Armature|jump_push_up|baselayer" (1.2s)
 *   animations/exercise-situps.glb         ← clip "Armature|situps|baselayer" (2.3s)
 *   animations/exercise-jumprope.glb       ← clip "Armature|Jump_Rope|baselayer" (8.6s)
 *   animations/gesture-finger-wag.glb      ← clip "Armature|Finger_Wag_No|baselayer" (5.033s, sin usar todavía)
 *
 * Todos los GLB comparten exactamente los mismos nombres de nodo/joint
 * (verificado byte a byte), por eso se puede cargar cada animación por
 * separado y aplicarla sobre el esqueleto clonado del modelo base sin
 * necesidad de "retargeting" — el AnimationMixer las bindea por nombre.
 *
 * Por qué no un solo GLB con todo: Meshy exporta cada animación como un
 * GLB completo (malla+textura+1 clip, ~7MB cada uno) en vez de un único
 * archivo con múltiples AnimationClip. Cargar los 12 de una (~88MB) de
 * entrada sería inviable en celular (punto 14 del brief), así que cada
 * clip de animación se descarga PEREZOSAMENTE (recién la primera vez que
 * se necesita) y se cachea en memoria — el resto de la sesión no vuelve
 * a pedirse por red.
 */
import * as THREE from 'three';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { clone as cloneSkinned } from 'three/addons/utils/SkeletonUtils.js';

const ASSET_BASE = 'assets/models/avatars/robot/';
const MODEL_URL = `${ASSET_BASE}robot-base.glb`;

const ANIMATION_FILES = {
  greetingGentleman: 'animations/greeting-bow-gentleman.glb',
  greetingFormal: 'animations/greeting-bow-formal.glb',
  success: 'animations/success-victory.glb',
  celebration: 'animations/celebration-victory-cheer.glb',
  levelup: 'animations/levelup-gangnam-groove.glb',
  running: 'animations/running.glb',
  walking: 'animations/walking.glb',
  exercise: 'animations/exercise-pushup.glb',
  exerciseSitups: 'animations/exercise-situps.glb',
  exerciseJumprope: 'animations/exercise-jumprope.glb',
  gesture: 'animations/gesture-finger-wag.glb',
};

// Animaciones en loop (no "one-shot"): no se auto-apagan solas, hay que
// pedirles explícitamente volver a idle (returnToIdle()).
const LOOPING_KEYS = new Set(['running', 'walking']);

export const SKINS = {
  classic: { label: 'NutriFit Classic', tint: 0xffffff },
  dark: { label: 'NutriFit Dark', tint: 0x8a8f98 },
  blue: { label: 'NutriFit Blue', tint: 0xa9d6ff },
  neon: { label: 'NutriFit Neon', tint: 0xa6ffb4 },
  gold: { label: 'NutriFit Gold', tint: 0xffd98a },
};
const DEFAULT_SKIN = 'classic';

// =======================================================================
// Configuración de calidad de render — decisión FINAL (ver informe de la
// pasada "calidad visual del avatar"). Se probó pixelRatio 2 / 2.5 / 3:
// en esta GPU de desarrollo el costo por frame es prácticamente idéntico
// (~0.05ms), pero sin una pantalla Retina real a mano no se puede
// comprobar una mejora VISUAL genuina de 2.5/3 sobre 2 — así que, siguiendo
// la propia regla de "si 3 no aporta una diferencia relevante, usar 2",
// se deja 2 como tope: ya cubre pantallas Retina comunes sin arriesgar
// rendimiento en celulares de gama media/baja que no pudimos medir acá.
const MAX_PIXEL_RATIO = 2;
const RENDER_EXPOSURE = 1.0;

// ---------------------------------------------------------------------
// Sombra de contacto compartida (geometría + textura generadas UNA sola
// vez para toda la sesión, reutilizadas por cada instancia — dashboard y
// modal usan el mismo par geometry/material, no se disponen nunca porque
// viven mientras viva la pestaña, igual que clipPromiseCache).
// ---------------------------------------------------------------------
let sharedShadowGeometry = null;
function getSharedShadowGeometry() {
  if (!sharedShadowGeometry) sharedShadowGeometry = new THREE.CircleGeometry(1, 48);
  return sharedShadowGeometry;
}
let sharedShadowMaterial = null;
function getSharedShadowMaterial() {
  if (sharedShadowMaterial) return sharedShadowMaterial;
  const size = 128;
  const canvas = document.createElement('canvas');
  canvas.width = size; canvas.height = size;
  const ctx = canvas.getContext('2d');
  const gradient = ctx.createRadialGradient(size / 2, size / 2, 0, size / 2, size / 2, size / 2);
  gradient.addColorStop(0, 'rgba(10,12,10,0.38)');
  gradient.addColorStop(0.6, 'rgba(10,12,10,0.16)');
  gradient.addColorStop(1, 'rgba(10,12,10,0)');
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, size, size);
  const texture = new THREE.CanvasTexture(canvas);
  sharedShadowMaterial = new THREE.MeshBasicMaterial({
    map: texture, transparent: true, depthWrite: false, side: THREE.DoubleSide,
  });
  return sharedShadowMaterial;
}

// ---------------------------------------------------------------------
// Carga y caché compartidos entre TODAS las instancias (dashboard/modal).
// El modelo base y cada clip se piden a red una sola vez por sesión.
// ---------------------------------------------------------------------
const gltfLoader = new GLTFLoader();

let baseModelPromise = null;
function loadBaseModel() {
  if (!baseModelPromise) {
    baseModelPromise = new Promise((resolve, reject) => {
      gltfLoader.load(MODEL_URL, resolve, undefined, reject);
    });
  }
  return baseModelPromise;
}

const clipPromiseCache = new Map();
function loadClip(key) {
  const file = ANIMATION_FILES[key];
  if (!file) return Promise.resolve(null);
  if (!clipPromiseCache.has(key)) {
    clipPromiseCache.set(key, new Promise((resolve) => {
      gltfLoader.load(`${ASSET_BASE}${file}`, (gltf) => {
        resolve(gltf.animations && gltf.animations[0] ? gltf.animations[0] : null);
      }, undefined, (err) => {
        console.error(`[NutriFit] No se pudo cargar la animación "${key}":`, err);
        resolve(null);
      });
    }));
  }
  return clipPromiseCache.get(key);
}

function resolveClipKey(semantic, opts) {
  if (semantic === 'greeting') return Math.random() < 0.5 ? 'greetingGentleman' : 'greetingFormal';
  if (semantic === 'exercise') {
    if (opts.variant === 'situps') return 'exerciseSitups';
    if (opts.variant === 'jumprope') return 'exerciseJumprope';
    return 'exercise';
  }
  return semantic;
}

// ---------------------------------------------------------------------
// createRobotAvatar(container, options) — misma firma/API pública que la
// versión procedural anterior (ver frontend/js/robot-avatar.js), para no
// romper a app.js: setSkin, setExpression, wave, celebrate, pulseHappy,
// setPaused, resize, destroy. Se agregan playAnimation/returnToIdle,
// nuevos, de forma aditiva.
// ---------------------------------------------------------------------
export function createRobotAvatar(container, options = {}) {
  const state = {
    skinKey: SKINS[options.skin] ? options.skin : DEFAULT_SKIN,
    destroyed: false,
    ready: false,
    pausedExternal: false,
    pausedHidden: typeof document !== 'undefined' && document.hidden,
    pausedOffscreen: false,
    currentKey: null,
    isPlayingSpecial: false,
  };

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(34, 1, 0.05, 30);

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setClearColor(0x000000, 0);
  // Calidad de imagen (punto 4 de la 2ª pasada): color sRGB correcto +
  // tone mapping filmico (ACES) — de paso, ACES "aplasta" con suavidad
  // las zonas sobreexpuestas, lo que ayuda a que un highlight de luz
  // sobre la cara no se vea como un reflejo blanco duro (punto 5/12).
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = RENDER_EXPOSURE;
  renderer.domElement.style.display = 'block';
  renderer.domElement.style.width = '100%';
  renderer.domElement.style.height = '100%';
  renderer.domElement.style.touchAction = 'manipulation';
  renderer.domElement.style.cursor = 'pointer';
  renderer.domElement.style.opacity = '0';
  renderer.domElement.style.transition = 'opacity 420ms ease, transform 150ms ease';
  container.appendChild(renderer.domElement);

  // Iluminación tipo "estudio de producto", neutra a propósito (sin tinte
  // de marca): hemisferio suave como base + key tenue + fill frío que
  // abre los negros + un rim neutro (blanco-azulado) que despega los
  // bordes del fondo SIN pintarlos de verde. Intensidades bajas: el
  // material tiene specular boosteado (KHR_materials_specular ×2) y la
  // cara/visor se diseñó para NO tener reflejos — una key fuerte volvería
  // a producir ese brillo blanco duro que se pidió evitar.
  const hemi = new THREE.HemisphereLight(0xffffff, 0x26262a, 0.9);
  const keyLight = new THREE.DirectionalLight(0xfff8ec, 0.72);
  keyLight.position.set(1.0, 2.6, 1.8);
  const fillLight = new THREE.DirectionalLight(0xeaf4ff, 0.36);
  fillLight.position.set(-1.4, 0.7, 1.4);
  const rimLight = new THREE.DirectionalLight(0xdfe7f2, 0.24);
  rimLight.position.set(-1.2, 1.5, -1.6);
  scene.add(hemi, keyLight, fillLight, rimLight);

  // Sombra elíptica suave debajo de los pies (reemplaza la plataforma +
  // halo violeta anterior, que ocupaba demasiado espacio visual y le
  // restaba protagonismo al robot — punto 6/13 de la 2ª pasada). Es una
  // textura generada una sola vez y compartida entre instancias.
  const shadow = new THREE.Mesh(getSharedShadowGeometry(), getSharedShadowMaterial());
  shadow.rotation.x = -Math.PI / 2;
  shadow.position.y = 0.002;
  scene.add(shadow);

  // ---- loader mientras se descarga el GLB (punto 15) ----
  const loaderEl = document.createElement('div');
  loaderEl.className = 'robot-loading';
  loaderEl.innerHTML = '<span class="robot-loading-spinner"></span>';
  container.appendChild(loaderEl);

  let root = null;
  let mixer = null;
  let currentAction = null;
  let restY = 0;
  let frameHeight = 1.7;
  let frameWidth = 0.6;
  let frameCenterY = 0.9;
  let idleSeed = Math.random() * Math.PI * 2;
  const clock = new THREE.Clock();
  const bodyMaterials = [];
  const reducedMotion = typeof window !== 'undefined' && window.matchMedia
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const idleAmplitude = reducedMotion ? 0.15 : 1;

  function isPaused() { return state.pausedExternal || state.pausedHidden || state.pausedOffscreen; }

  /** THREE.Box3().setFromObject() no sirve para una SkinnedMesh: usa la
   *  geometría en espacio local de bind-pose (donde el personaje suele
   *  quedar centrado cerca del origen), sin aplicar la deformación real
   *  del esqueleto — da una caja chica y desplazada que no representa la
   *  silueta real (verificado: con este modelo devolvía ~0.56 unidades
   *  de alto en vez de ~1.03, y quedaba centrada a la altura de los pies
   *  en vez del cuerpo completo). En su lugar, medimos la silueta real a
   *  partir de la posición mundial de cada hueso del esqueleto (ya
   *  actualizada por el AnimationMixer/pose actual) y le sumamos un
   *  margen, porque la piel sobresale un poco más allá del pivote de
   *  cada hueso (manos, punta del pie, parte superior de la cabeza). */
  function computeSkeletonWorldBox(skinnedMesh) {
    const box = new THREE.Box3();
    const wp = new THREE.Vector3();
    skinnedMesh.skeleton.bones.forEach((bone) => {
      bone.getWorldPosition(wp);
      box.expandByPoint(wp);
    });
    const size = box.getSize(new THREE.Vector3());
    // Márgenes ajustados (2ª pasada, punto 2/3): antes eran mucho más
    // generosos (0.16/0.04/0.18) e inflaban la caja de encuadre bastante
    // más allá de la silueta real, lo que obligaba a la cámara a alejarse
    // de más y dejaba al robot con demasiado aire alrededor.
    box.max.y += size.y * 0.06; // margen para el tope real de la cabeza
    box.min.y -= size.y * 0.015; // margen para la suela del pie
    box.max.x += size.x * 0.1; box.min.x -= size.x * 0.1; // manos/hombros
    box.max.z += size.z * 0.1; box.min.z -= size.z * 0.1;
    return box;
  }

  // ---- encuadre automático vía bounding box (punto 11) ----
  function measureAndCenter(object) {
    let skinnedMesh = null;
    object.traverse((o) => { if (o.isSkinnedMesh && !skinnedMesh) skinnedMesh = o; });
    const box = skinnedMesh ? computeSkeletonWorldBox(skinnedMesh) : new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    object.position.x -= center.x;
    object.position.z -= center.z;
    object.position.y -= box.min.y; // pies apoyados en y=0
    restY = object.position.y;
    frameHeight = size.y || 1.7;
    frameWidth = size.x || 0.6; // ancho real (hombros/brazos) — ver applyCameraFraming
    frameCenterY = frameHeight * 0.52;

    const footprint = Math.max(size.x, size.z) || 0.6;
    shadow.scale.setScalar(footprint * 1.05);
  }

  // Fracción vertical del encuadre que debe ocupar el personaje (punto 1
  // de la pasada visual: 70-80% de la altura del stage). 1/FILL_FACTOR ≈
  // fracción de alto ocupada — con margen para que cabeza y pies no
  // queden pegados al borde del contenedor.
  const FILL_FACTOR = 1.25;

  function applyCameraFraming() {
    const vFov = (camera.fov * Math.PI) / 180;
    const aspect = camera.aspect || 1;
    const fitHeightDistance = (frameHeight / 2) / Math.tan(vFov / 2);
    // BUG corregido (pasada visual): esto usaba "fitHeightDistance / aspect",
    // que en los hechos calculaba "a qué distancia hay que estar para que
    // el ALTO del personaje (frameHeight) entre horizontalmente" — como
    // frameHeight (~1.7m) es mucho mayor que el ancho real del robot
    // (hombros, ~0.5-0.6m), la cámara se alejaba muchísimo más de lo
    // necesario en cualquier stage angosto/alto (mobile, columna del
    // Home desktop), dejando al robot chico con aire de sobra arriba y
    // abajo. Ahora se usa el ancho real medido (frameWidth).
    const fitWidthDistance = (frameWidth / 2) / (Math.max(aspect, 0.35) * Math.tan(vFov / 2));
    const distance = Math.max(fitHeightDistance, fitWidthDistance) * FILL_FACTOR;
    camera.position.set(0, frameHeight * 0.56, distance);
    camera.near = Math.max(distance / 100, 0.01);
    camera.far = distance * 8;
    camera.updateProjectionMatrix();
    camera.lookAt(0, frameCenterY, 0);
  }

  // Diagnóstico de resolución/color/cámara — sólo lee valores existentes,
  // no cambia nada. Público y bajo demanda (no corre solo): útil para
  // verificar en cualquier momento que el canvas interno tenga la
  // resolución física correcta para su tamaño CSS.
  function getDiagnostics() {
    const canvas = renderer.domElement;
    return {
      cssSize: `${canvas.clientWidth}x${canvas.clientHeight}`,
      internalCanvasSize: `${canvas.width}x${canvas.height}`,
      devicePixelRatio: window.devicePixelRatio,
      pixelRatioUsado: renderer.getPixelRatio(),
      maxAnisotropyDisponible: renderer.capabilities.getMaxAnisotropy(),
      outputColorSpace: renderer.outputColorSpace,
      toneMapping: renderer.toneMapping === THREE.ACESFilmicToneMapping ? 'ACESFilmicToneMapping' : renderer.toneMapping,
      toneMappingExposure: renderer.toneMappingExposure,
      fov: camera.fov,
    };
  }

  function resize() {
    const w = Math.max(1, container.clientWidth);
    const h = Math.max(1, container.clientHeight);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO));
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    if (root) applyCameraFraming();
  }
  const resizeObserver = new ResizeObserver(() => resize());
  resizeObserver.observe(container);
  resize();

  // ---- visibilidad / pausa (idéntico criterio a la versión anterior) ----
  function onVisibilityChange() { state.pausedHidden = document.hidden; }
  document.addEventListener('visibilitychange', onVisibilityChange);
  let intersectionObserver = null;
  if ('IntersectionObserver' in window) {
    intersectionObserver = new IntersectionObserver((entries) => {
      state.pausedOffscreen = !entries[entries.length - 1].isIntersecting;
    }, { threshold: 0.01 });
    intersectionObserver.observe(container);
  }

  // ---- skin: tinte de color sobre el material único del modelo ----
  function applySkin(key) {
    state.skinKey = SKINS[key] ? key : DEFAULT_SKIN;
    const tint = SKINS[state.skinKey].tint;
    bodyMaterials.forEach((mat) => {
      mat.color.setHex(tint);
      mat.emissive.setHex(tint);
      mat.emissiveIntensity = state.skinKey === 'classic' ? 1 : 0.85;
    });
  }
  function setSkin(key) { applySkin(key); }

  // `setExpression` existía en la versión procedural (dibujaba una cara
  // en un canvas). El modelo de Meshy trae la cara esculpida/texturizada
  // de fábrica — no hay un plano de cara aparte para redibujar. Se deja
  // el método (para no romper llamadas existentes) como no-op seguro.
  function setExpression(_name) { /* no aplica al modelo GLB; ver comentario arriba */ }

  function setPaused(paused) { state.pausedExternal = !!paused; }

  // ---- animaciones ----
  function onMixerFinished(e) {
    if (e.action !== currentAction) return;
    e.action.fadeOut(0.4);
    currentAction = null;
    state.isPlayingSpecial = false;
    state.currentKey = null;
  }

  async function playAnimation(semanticKey, opts = {}) {
    if (state.destroyed || !mixer) return;
    const resolvedKey = resolveClipKey(semanticKey, opts);
    const clip = await loadClip(resolvedKey);
    if (!clip || state.destroyed || !mixer) return;

    const looping = LOOPING_KEYS.has(resolvedKey) || !!opts.loop;
    const action = mixer.clipAction(clip);
    action.reset();
    action.setLoop(looping ? THREE.LoopRepeat : THREE.LoopOnce, looping ? Infinity : 1);
    action.clampWhenFinished = !looping;
    action.enabled = true;
    action.setEffectiveWeight(1);
    action.fadeIn(0.3);
    action.play();

    if (currentAction && currentAction !== action) currentAction.fadeOut(0.3);
    currentAction = action;
    state.isPlayingSpecial = true;
    state.currentKey = semanticKey;
  }

  function returnToIdle(fadeDuration = 0.4) {
    if (currentAction) currentAction.fadeOut(fadeDuration);
    currentAction = null;
    state.isPlayingSpecial = false;
    state.currentKey = null;
  }

  // ---- API pública compatible con la versión anterior ----
  function wave() { playAnimation('greeting'); }
  function celebrate() { playAnimation('levelup'); spawnParticles(); }
  function pulseHappy() { playAnimation('success'); }

  function onPointerDown() { wave(); }
  renderer.domElement.addEventListener('pointerdown', onPointerDown, { passive: true });

  // ---- partículas de celebración (mismo recurso visual que antes) ----
  function spawnParticles() {
    const count = 18;
    const positions = new Float32Array(count * 3);
    const velocities = [];
    for (let i = 0; i < count; i++) {
      positions[i * 3 + 1] = frameHeight * 0.7;
      const angle = Math.random() * Math.PI * 2;
      const speed = 0.5 + Math.random() * 0.7;
      velocities.push([Math.cos(angle) * speed, 1 + Math.random() * 1.1, Math.sin(angle) * speed]);
    }
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    const material = new THREE.PointsMaterial({ color: 0x8be422, size: 0.06, transparent: true, opacity: 1, depthWrite: false, blending: THREE.AdditiveBlending });
    const points = new THREE.Points(geometry, material);
    scene.add(points);
    const start = performance.now();
    const dur = 820;
    const baseY = frameHeight * 0.7;
    function cleanup() { scene.remove(points); geometry.dispose(); material.dispose(); }
    function step(now) {
      if (state.destroyed) { cleanup(); return; }
      const p = Math.min(1, (now - start) / dur);
      const pos = geometry.attributes.position.array;
      for (let i = 0; i < count; i++) {
        pos[i * 3] = velocities[i][0] * p;
        pos[i * 3 + 1] = baseY + velocities[i][1] * p - 1.1 * p * p;
        pos[i * 3 + 2] = velocities[i][2] * p;
      }
      geometry.attributes.position.needsUpdate = true;
      material.opacity = 1 - p;
      if (p < 1) requestAnimationFrame(step); else cleanup();
    }
    requestAnimationFrame(step);
  }

  // ---- carga del modelo base ----
  async function mount() {
    try {
      const baseGltf = await loadBaseModel();
      if (state.destroyed) return;

      const maxAniso = renderer.capabilities.getMaxAnisotropy();
      root = cloneSkinned(baseGltf.scene);
      root.traverse((obj) => {
        if (obj.isMesh) {
          obj.frustumCulled = false;
          if (obj.material) {
            bodyMaterials.push(obj.material);
            ['map', 'emissiveMap'].forEach((slot) => {
              if (obj.material[slot]) obj.material[slot].anisotropy = maxAniso;
            });
          }
        }
      });
      scene.add(root);
      measureAndCenter(root);
      applyCameraFraming();
      applySkin(state.skinKey);

      mixer = new THREE.AnimationMixer(root);
      mixer.addEventListener('finished', onMixerFinished);

      loaderEl.remove();
      requestAnimationFrame(() => { renderer.domElement.style.opacity = '1'; });
      state.ready = true;
    } catch (err) {
      console.error('[NutriFit] No se pudo cargar el avatar 3D:', err);
      loaderEl.innerHTML = '<div class="robot-fallback">🤖</div>';
    }
  }
  mount();

  // ---- loop de render único (punto 3 / 14) ----
  let rafId = null;
  function frame() {
    if (state.destroyed) return;
    rafId = requestAnimationFrame(frame);
    const delta = clock.getDelta();
    if (isPaused()) return;

    if (mixer) mixer.update(delta);

    if (root && !state.isPlayingSpecial) {
      const t = clock.elapsedTime + idleSeed;
      root.position.y = restY + Math.sin(t * 1.5) * 0.012 * idleAmplitude;
      root.rotation.z = Math.sin(t * 0.7) * 0.012 * idleAmplitude;
      root.rotation.y = Math.sin(t * 0.35) * 0.02 * idleAmplitude;
    }

    renderer.render(scene, camera);
  }
  rafId = requestAnimationFrame(frame);

  function destroy() {
    if (state.destroyed) return;
    state.destroyed = true;
    if (rafId) cancelAnimationFrame(rafId);
    resizeObserver.disconnect();
    if (intersectionObserver) intersectionObserver.disconnect();
    document.removeEventListener('visibilitychange', onVisibilityChange);
    renderer.domElement.removeEventListener('pointerdown', onPointerDown);
    if (mixer) mixer.removeEventListener('finished', onMixerFinished);

    // Nota: geometry/material/texture del modelo, y la geometría/material
    // de la sombra (getSharedShadowGeometry/Material), son COMPARTIDOS
    // entre todas las instancias (dashboard + modal) — no se disponen acá
    // para no romper otra instancia que siga viva. Sólo se libera lo
    // propio de esta instancia (escena/renderer/canvas).
    renderer.dispose();
    if (typeof renderer.forceContextLoss === 'function') renderer.forceContextLoss();
    if (renderer.domElement.parentNode) renderer.domElement.parentNode.removeChild(renderer.domElement);
  }

  // Benchmark síncrono de render (punto 12 de la prueba A/B): mide el
  // costo real de renderer.render() en un loop bloqueante de N frames, sin
  // depender de requestAnimationFrame — en entornos donde el pane/tab no
  // está compuesto en pantalla, los navegadores suspenden rAF aunque
  // document.hidden diga "false", así que el contador de FPS del loop
  // normal no sirve ahí. Esto sí da un número real y comparable.
  function benchmarkRender(frames = 90) {
    const t0 = performance.now();
    for (let i = 0; i < frames; i++) renderer.render(scene, camera);
    const t1 = performance.now();
    const msPerFrame = (t1 - t0) / frames;
    return {
      pixelRatio: renderer.getPixelRatio(),
      canvasInterno: `${renderer.domElement.width}x${renderer.domElement.height}`,
      frames,
      totalMs: +(t1 - t0).toFixed(1),
      msPorFrame: +msPerFrame.toFixed(3),
      estFps: +(1000 / msPerFrame).toFixed(1),
    };
  }

  return {
    setSkin, setExpression, wave, celebrate, pulseHappy, setPaused, resize, destroy,
    playAnimation, returnToIdle,
    // Aditivo — diagnóstico bajo demanda, no rompe el contrato existente,
    // ningún llamador previo lo necesita.
    getDiagnostics,
    benchmarkRender,
  };
}
