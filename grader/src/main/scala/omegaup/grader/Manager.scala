package omegaup.grader

import java.io._
import javax.servlet._
import javax.servlet.http._
import org.eclipse.jetty.server.Request
import org.eclipse.jetty.server.handler._
import net.liftweb.json._
import omegaup._
import omegaup.data._
import omegaup.runner._
import omegaup.broadcaster.Broadcaster
import Status._
import Language._
import Veredict._
import Validator._
import Server._

class RunnerEndpoint(val host: String, val port: Int) {
	def ==(o: RunnerEndpoint) = host == o.host && port == o.port
	override def hashCode() = 28227 + 97 * host.hashCode + port
	override def equals(other: Any) = other match {
		case x:RunnerEndpoint => host == x.host && port == x.port
		case _ => false
	}
}

object Manager extends Object with Log {
	private val registeredEndpoints = scala.collection.mutable.HashMap.empty[RunnerEndpoint, Long]
	private val runnerQueue = new java.util.concurrent.LinkedBlockingQueue[RunnerService]()

	// Loading SQL connector driver
	Class.forName(Config.get("db.driver", "org.h2.Driver"))
	val connection = java.sql.DriverManager.getConnection(
		Config.get("db.url", "jdbc:h2:file:omegaup"),
		Config.get("db.user", "omegaup"),
		Config.get("db.password", "")
	)

	def recoverQueue() = {
		implicit val conn = connection

		val pendingRuns = GraderData.pendingRuns

		info("Recovering previous queue: {} runs re-added", pendingRuns.size)
		
		pendingRuns map grade
	}

	def grade(run: Run): GradeOutputMessage = {
		info("Judging {}", run.id)

		implicit val conn = connection

		run.status = Status.Waiting
		run.veredict = Veredict.JudgeError

		GraderData.update(run)

		val driver = if (run.problem.validator == Validator.Remote) {
			throw new UnsupportedOperationException("Remote validators not supported anymore")
		} else {
			drivers.OmegaUp
		}
	
		info("Using driver {}", driver)

		driver ! drivers.Submission(run)
		
		new GradeOutputMessage()
	}
	
	def grade(id: Long): GradeOutputMessage = {
		implicit val conn = connection
		
		GraderData.run(id) match {
			case None => throw new IllegalArgumentException("Id " + id + " not found")
			case Some(run) => grade(run)
		}
	}
	
	def getRunner(): RunnerService = {
		var r: RunnerService = null

		while (r == null) {
			info("Runner queue length {} known endpoints {}", runnerQueue.size, registeredEndpoints.size)
			r = runnerQueue.take()
			if (!Config.get("grader.embedded_runner.enable", false) && r.isInstanceOf[omegaup.runner.Runner]) {
				// don't put this runner back in the queue.
				r = null
			}
		}

		r
	}
	
	def addRunner(service: RunnerService) = {
		runnerQueue.put(service)
		info("Runner queue length {} known endpoints {}", runnerQueue.size, registeredEndpoints.size)
	}

	def pruneEndpointsLocked() = {
		registeredEndpoints.keys foreach { endpoint => {
			// Remove any endpoint that hasn't called back in more than 10 minutes.
			if (registeredEndpoints(endpoint) < System.currentTimeMillis - 10 * 60 * 1000) {
				info("Expiring {}:{}", endpoint.host, endpoint.port)
				registeredEndpoints -= endpoint
			}
		}}
	}
	
	def register(host: String, port: Int): RegisterOutputMessage = {
		val endpoint = new RunnerEndpoint(host, port)
	
		synchronized (registeredEndpoints) {
			pruneEndpointsLocked
			if (!registeredEndpoints.contains(endpoint)) {
				info("Registering {}:{}", endpoint.host, endpoint.port)
				registeredEndpoints += endpoint -> 0
				addRunner(new RunnerProxy(endpoint.host, endpoint.port))
			}
			registeredEndpoints(endpoint) = System.currentTimeMillis
			endpoint
		}

		info("Runner queue length {} known endpoints {}", runnerQueue.size, registeredEndpoints.size)
				
		new RegisterOutputMessage()
	}
	
	def deregister(host: String, port: Int): RegisterOutputMessage = {
		val endpoint = new RunnerEndpoint(host, port)
		
		synchronized (registeredEndpoints) {
			pruneEndpointsLocked
			if (registeredEndpoints.contains(endpoint)) {
				info("De-registering {}:{}", endpoint.host, endpoint.port)
				registeredEndpoints -= endpoint
				runnerQueue.remove(new RunnerProxy(endpoint.host, endpoint.port))
			}
			endpoint
		}

		info("Runner queue length {} known endpoints {}", runnerQueue.size, registeredEndpoints.size)
		
		new RegisterOutputMessage()
	}
	
	def updateVeredict(run: Run): Run = {
		info("Veredict update: {} {} {} {} {} {} {}", run.id, run.status, run.veredict, run.score, run.contest_score, run.runtime, run.memory)
		
		implicit val conn = connection
		
		Broadcaster.update(run)
		GraderData.update(run)
	}
	
	def init(configPath: String) = {
		import omegaup.data._

		// shall we create an embedded runner?
		if(Config.get("grader.embedded_runner.enable", false)) {
      // Choose a sandbox instance
      val sandbox = Config.get("runner.sandbox", "box") match {
        case "box" => Box
        case "minijail" => Minijail
      }
			Manager.addRunner(new omegaup.runner.Runner(sandbox))
		}

		// the handler
		val handler = new AbstractHandler() {
			@throws(classOf[IOException])
			@throws(classOf[ServletException])
			override def handle(target: String, baseRequest: Request, request: HttpServletRequest, response: HttpServletResponse): Unit = {
				implicit val formats = Serialization.formats(NoTypeHints)
				
				response.setContentType("text/json")
				
				Serialization.write(request.getPathInfo() match {
					case "/reload-config/" => {
						try {
							val req = Serialization.read[ReloadConfigInputMessage](request.getReader())
							val embeddedRunner = Config.get("grader.embedded_runner.enable", false)
							Config.load(configPath)

							req.overrides match {
								case Some(x) => {
									info("Configuration reloaded {}", x)
									x.foreach { case (k, v) => Config.set(k, v) }
								}
								case None => info("Configuration reloaded")
							}

							Logging.init()

							if (Config.get("grader.embedded_runner.enable", false) && !embeddedRunner) {
                // Choose a sandbox instance
                val sandbox = Config.get("runner.sandbox", "box") match {
                  case "box" => Box
                  case "minijail" => Minijail
                }
                Manager.addRunner(new omegaup.runner.Runner(sandbox))
							}

							response.setStatus(HttpServletResponse.SC_OK)
							new ReloadConfigOutputMessage()
						} catch {
							case e: Exception => {
								error("Reload config: {}", e)
								response.setStatus(HttpServletResponse.SC_BAD_REQUEST)
								new ReloadConfigOutputMessage(status = "error", error = Some(e.getMessage))
							}
						}
					}
					case "/status/" => {
						response.setStatus(HttpServletResponse.SC_OK)
						new StatusOutputMessage(embedded_runner = Config.get("grader.embedded_runner.enable", false), runner_queue_length = runnerQueue.size, runners = registeredEndpoints.size)
					}
					case "/grade/" => {
						try {
							val req = Serialization.read[GradeInputMessage](request.getReader())
							response.setStatus(HttpServletResponse.SC_OK)
							Manager.grade(req.id)
						} catch {
							case e: IllegalArgumentException => {
								error("Grade failed: {}", e)
								response.setStatus(HttpServletResponse.SC_NOT_FOUND)
								new GradeOutputMessage(status = "error", error = Some(e.getMessage))
							}
							case e: Exception => {
								error("Grade failed: {}", e)
								response.setStatus(HttpServletResponse.SC_BAD_REQUEST)
								new GradeOutputMessage(status = "error", error = Some(e.getMessage))
							}
						}
					}
					case "/register/" => {
						try {
							val req = Serialization.read[RegisterInputMessage](request.getReader())
							response.setStatus(HttpServletResponse.SC_OK)
							Manager.register(request.getRemoteAddr, req.port)
						} catch {
							case e: Exception => {
								error("Register failed: {}", e)
								response.setStatus(HttpServletResponse.SC_BAD_REQUEST)
								new RegisterOutputMessage(status = "error", error = Some(e.getMessage))
							}
						}
					}
					case "/deregister/" => {
						try {
							val req = Serialization.read[RegisterInputMessage](request.getReader())
							response.setStatus(HttpServletResponse.SC_OK)
							Manager.deregister(request.getRemoteAddr, req.port)
						} catch {
							case e: Exception => {
								response.setStatus(HttpServletResponse.SC_BAD_REQUEST)
								new RegisterOutputMessage(status = "error", error = Some(e.getMessage))
							}
						}
					}
					case _ => {
						response.setStatus(HttpServletResponse.SC_NOT_FOUND)
						new NullMessage()
					}
				}, response.getWriter())
				
				baseRequest.setHandled(true)
			}
		};

		// start the drivers
		drivers.OmegaUp.start
		//drivers.UVa.start
		//drivers.TJU.start
		//drivers.LiveArchive.start

		//drivers.UVa ! drivers.Login

		// boilerplate code for jetty with https support	
		val server = new org.eclipse.jetty.server.Server()
	
		val sslContext = new org.eclipse.jetty.util.ssl.SslContextFactory(Config.get[String]("grader.keystore", "omegaup.jks"))
		sslContext.setKeyManagerPassword(Config.get[String]("grader.password", "omegaup"))
		sslContext.setKeyStorePassword(Config.get[String]("grader.keystore.password", "omegaup"))
		sslContext.setTrustStore(Config.get[String]("grader.truststore", "omegaup.jks"))
		sslContext.setTrustStorePassword(Config.get[String]("grader.truststore.password", "omegaup"))
		sslContext.setNeedClientAuth(true)
	
		val graderConnector = new org.eclipse.jetty.server.ssl.SslSelectChannelConnector(sslContext)
		graderConnector.setPort(Config.get[Int]("grader.port", 21680))
				
		server.setConnectors(List(graderConnector).toArray)
		
		server.setHandler(handler)
		server.start()

		info("Omegaup started")

		Manager.recoverQueue
		
		server
	}
	
	def main(args: Array[String]) = {
		// Setting keystore properties
		System.setProperty("javax.net.ssl.keyStore", Config.get("grader.keystore", "omegaup.jks"))
		System.setProperty("javax.net.ssl.trustStore", Config.get("grader.truststore", "omegaup.jks"))
		System.setProperty("javax.net.ssl.keyStorePassword", Config.get("grader.keystore.password", "omegaup"))
		System.setProperty("javax.net.ssl.trustStorePassword", Config.get("grader.truststore.password", "omegaup"))
		
		// Parse command-line options.
		var configPath = "omegaup.conf"
		var i = 0
		while (i < args.length) {
			if (args(i) == "--config" && i + 1 < args.length) {
				i += 1
				configPath = args(i)
				Config.load(configPath)
			} else if (args(i) == "--output" && i + 1 < args.length) {
				i += 1
				System.setOut(new java.io.PrintStream(new java.io.FileOutputStream(args(i))))
			}
			i += 1
		}

		// logger
		Logging.init()
		
		val server = init(configPath)

		Runtime.getRuntime.addShutdownHook(new Thread() {
			override def run() = {
				info("Shutting down")
				server.stop()
			}
		});
		
		server.join()
	}
}
