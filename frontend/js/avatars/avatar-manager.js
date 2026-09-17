/**
 * AvatarManager — registro genérico de "tipos" de avatar disponibles.
 *
 * Hoy sólo existe el tipo "robot" (ver ./robot.js). La idea es que más
 * adelante se puedan sumar "male" / "female" (avatares humanos, con
 * remeras/pantalones/peinados, ver punto 16 del pedido) simplemente
 * registrándolos acá con AvatarManager.register(tipo, modulo) — sin tener
 * que tocar nada del código que ya usa robots (app.js, robot-avatar.js).
 *
 * Cada módulo registrado debe exponer al menos:
 *   create(container, options) → controller { destroy(), ... }
 */
const registry = new Map();

export const DEFAULT_AVATAR_TYPE = 'robot';

export const AvatarManager = {
  register(type, avatarModule) {
    registry.set(type, avatarModule);
  },

  has(type) {
    return registry.has(type);
  },

  types() {
    return Array.from(registry.keys());
  },

  /** Crea una instancia del avatar del tipo pedido (o el tipo por defecto
   *  si no se especifica / no está registrado todavía). */
  create(type, container, options = {}) {
    const resolvedType = registry.has(type) ? type : DEFAULT_AVATAR_TYPE;
    const avatarModule = registry.get(resolvedType);
    if (!avatarModule) {
      throw new Error(`AvatarManager: no hay ningún tipo de avatar registrado ("${type}" pedido).`);
    }
    return avatarModule.create(container, options);
  },
};
