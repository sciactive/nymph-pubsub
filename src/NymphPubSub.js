// Uses AMD or browser globals.
(function (factory) {
    if (typeof define === 'function' && define.amd) {
        // AMD. Register as a module.
        define('NymphPubSub', ['NymphEntity', 'Nymph', 'NymphOptions', 'Promise'], factory);
    } else {
        // Browser globals
        factory(Entity, Nymph, NymphOptions, Promise);
    }
}(function(Entity, Nymph, NymphOptions, Promise){
	NymphPubSub = {
		// === Class Variables ===
		connection: null,
		subscriptions: {
			queries: {},
			uids: {}
		},

		// === Class Methods ===
		init: function(NymphOptions){
			this.pubsubURL = NymphOptions.pubsubURL;
			this.rateLimit = NymphOptions.rateLimit;

			this.connect();

			return this;
		},

		connect: function(){
			var that = this;

			this.connection = new WebSocket(this.pubsubURL);
			this.connection.onopen = function(e) {
				console.log("Nymph-PubSub connection established!");
			};

			this.connection.onmessage = function(e) {
				var data = JSON.parse(e.data);
				if (typeof data.query !== "undefined" && typeof that.subscriptions.queries[data.query] !== "undefined") {
					Nymph.getEntities.apply(Nymph, JSON.parse(data.query)).then(function(){
						for (var i=0; i < that.subscriptions.queries[data.query].length; i++) {
							that.subscriptions.queries[data.query][i][0].apply(this, arguments);
						}
					}, function(){
						for (var i=0; i < that.subscriptions.queries[data.query].length; i++) {
							that.subscriptions.queries[data.query][i][1].apply(this, arguments);
						}
					});
				}
			};
		},

		subscribeQuery: function(query, callbacks){
			if (typeof this.subscriptions.queries[query] === "undefined") {
				this.subscriptions.queries[query] = [];
				this.connection.send(JSON.stringify({
					"action": "subscribe",
					"query": query
				}));
			}
			this.subscriptions.queries[query].push(callbacks);
		},

		unsubscribeQuery: function(query, callbacks){
			if (typeof this.subscriptions.queries[query] === "undefined") {
				return;
			}
			var idx = this.subscriptions.queries[query].indexOf(callbacks);
			if (idx === -1) {
				return;
			}
			this.subscriptions.queries[query].splice(idx, 1);
			if (!this.subscriptions.queries[query].length) {
				delete this.subscriptions.queries[query];
				this.connection.send(JSON.stringify({
					"action": "unsubscribe",
					"query": query
				}));
			}
		},

		subscribeUID: function(name, callbacks){
			if (typeof this.subscriptions.uids[name] === "undefined") {
				this.subscriptions.uids[name] = [];
				this.connection.send(JSON.stringify({
					"action": "subscribe",
					"uid": name
				}));
			}
			this.subscriptions.uids[name].push(callbacks);
		},

		unsubscribeUID: function(name, callbacks){
			if (typeof this.subscriptions.uids[name] === "undefined") {
				return;
			}
			var idx = this.subscriptions.uids[name].indexOf(callbacks);
			if (idx === -1) {
				return;
			}
			this.subscriptions.uids[name].splice(idx, 1);
			if (!this.subscriptions.uids[name].length) {
				delete this.subscriptions.uids[name];
				this.connection.send(JSON.stringify({
					"action": "unsubscribe",
					"uid": name
				}));
			}
		}
	};

	// Override the original Nymph methods to allow subscriptions.
	var getEntities = Nymph.getEntities,
		getEntity = Nymph.getEntity,
		getUID = Nymph.getUID;
	Nymph.getEntities = function(){
		var args = Array.prototype.slice.call(arguments);
		var promise = getEntities.apply(Nymph, args);
		promise.query = JSON.stringify(args);
		promise.subscribe = function(resolve, reject){
			var callbacks = [resolve, reject];

			promise.then(resolve, reject);

			NymphPubSub.subscribeQuery(promise.query, callbacks);
			return {
				unsubscribe: function(){
					NymphPubSub.unsubscribeQuery(promise.query, callbacks);
				}
			};
		};
		return promise;
	};
	Nymph.getEntity = function(){
		var args = Array.prototype.slice.call(arguments);
		var promise = getEntity.apply(Nymph, args);
		args[0].limit = 1;
		promise.query = JSON.stringify(args);
		promise.subscribe = function(resolve, reject){
			var newResolve = function(args){
				if (!args.length) {
					resolve(null);
				} else {
					resolve(args[0]);
				}
			};
			var callbacks = [newResolve, reject];

			promise.then(resolve, reject);

			NymphPubSub.subscribeQuery(promise.query, callbacks);
			return {
				unsubscribe: function(){
					NymphPubSub.unsubscribeQuery(promise.query, callbacks);
				}
			};
		};
		return promise;
	};
	Nymph.getUID = function(name){
		var promise = getUID.apply(Nymph, [name]);
		promise.subscribe = function(resolve, reject){
			var callbacks = [resolve, reject];

			promise.then(resolve, reject);

			NymphPubSub.subscribeUID(name, callbacks);
			return {
				unsubscribe: function(){
					NymphPubSub.unsubscribeUID(name, callbacks);
				}
			};
		};
		return promise;
	};

	return NymphPubSub.init(NymphOptions);
}));
