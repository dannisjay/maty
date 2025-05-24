export default {
  async fetch(request, env, ctx) {
    let url = new URL(request.url);
    if(url.pathname.startsWith('/')){
      url.hostname="translate.google.com"; // 修改成自己的节点IP/域名
      let new_request = new Request(url, request)
      return await fetch(new_request)
    }
    return await env.ASSETS.fetch(request);
  },
};
