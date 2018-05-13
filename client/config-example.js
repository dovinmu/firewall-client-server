var config = {

  // This is where the client will send translation requests and broadcast its
  // images.
  serverURL: 'https://localhost:4430/',
  
  // WordPress URL
  libraryURL: 'https://localhost:4747/wp-admin/admin-ajax.php?action=fwc_submit_images',

  // Set this to the 'private_key_id' value from service-key.json
  sharedSecret: '...',

  // This will send all non-Baidu traffic through a SOCKS proxy on port 8888
  enableProxy: false,

  // This will define what traffic is not sent over SOCKS proxy
  bypassList: ["*.google.com", "*.gstatic.com", "*google*"],


};
