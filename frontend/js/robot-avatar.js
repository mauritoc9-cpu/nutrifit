/**
 * NutriFit — Punto de entrada del avatar (compatibilidad hacia atrás).
 * =====================================================================
 * La implementación real vive ahora en frontend/js/avatars/ (ver
 * avatar-manager.js + robot.js), preparada para poder sumar más
 * adelante otros tipos de avatar ("male"/"female") sin reescribir esto.
 *
 * Este archivo se mantiene con el mismo nombre y expone exactamente la
 * misma API pública que antes (`window.NutriFitRobotAvatar` con
 * create/SKINS, y el evento "nutrifit-robot-ready") porque app.js ya
 * depende de eso — no hacía falta tocar app.js para el cambio de modelo.
 */
import { createRobotAvatar, SKINS } from './avatars/robot.js';
import { AvatarManager, DEFAULT_AVATAR_TYPE } from './avatars/avatar-manager.js';

AvatarManager.register('robot', { create: createRobotAvatar });
// male/female se registrarán acá el día que existan, ej:
// AvatarManager.register('male', { create: createMaleAvatar });
// AvatarManager.register('female', { create: createFemaleAvatar });

if (typeof window !== 'undefined') {
  window.AvatarManager = AvatarManager;
  window.NutriFitRobotAvatar = { create: createRobotAvatar, SKINS };
  window.NutriFitAvatarManager = { create: (container, options) => AvatarManager.create(DEFAULT_AVATAR_TYPE, container, options) };
  window.dispatchEvent(new CustomEvent('nutrifit-robot-ready'));
}
