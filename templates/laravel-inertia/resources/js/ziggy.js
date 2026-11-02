const Ziggy = {"url":"http:\/\/localhost","port":null,"defaults":{},"routes":{"sesame.login":{"uri":"login","methods":["GET","HEAD"]},"sesame.logout":{"uri":"logout","methods":["POST"]},"home":{"uri":"\/","methods":["GET","HEAD"]},"dashboard":{"uri":"dashboard","methods":["GET","HEAD"]}}};
if (typeof window !== 'undefined' && typeof window.Ziggy !== 'undefined') {
  Object.assign(Ziggy.routes, window.Ziggy.routes);
}
export { Ziggy };
