name := "Grader"

version := "1.1"

organization := "omegaup"

scalaVersion := "2.10.3"

scalacOptions ++= Seq("-unchecked", "-deprecation", "-feature")

exportJars := true

packageOptions in (Compile, packageBin) +=
    Package.ManifestAttributes( java.util.jar.Attributes.Name.MAIN_CLASS -> "omegaup.Service" )

libraryDependencies ++= Seq(
  "org.eclipse.jetty" % "jetty-server" % "8.1.14.v20131031",
  "org.eclipse.jetty.orbit" % "javax.servlet" % "3.0.0.v201112011016" artifacts(Artifact("javax.servlet", "jar", "jar")),
  "org.eclipse.jetty" % "jetty-client" % "8.1.14.v20131031",
  "org.eclipse.jetty" % "jetty-security" % "8.1.14.v20131031",
  "org.eclipse.jetty" % "jetty-websocket" % "8.1.14.v20131031",
  "net.liftweb" %% "lift-json" % "2.5.1",
  "org.slf4j" % "log4j-over-slf4j" % "1.7.5",
  "ch.qos.logback" % "logback-core" % "1.0.13",
  "ch.qos.logback" % "logback-classic" % "1.0.13",
  "commons-codec" % "commons-codec" % "1.8",
  "org.scalatest" %% "scalatest" % "2.0" % "test",
  "com.h2database" % "h2" % "1.3.153" % "runtime",
  "mysql" % "mysql-connector-java" % "5.1.12" % "runtime"
)

proguardSettings 

ProguardKeys.options in Proguard ++= Seq(
  "-dontskipnonpubliclibraryclasses",
  "-dontskipnonpubliclibraryclassmembers",
  "-dontoptimize",
  "-dontobfuscate",
  "-dontpreverify",
  "-dontnote",
  "-dontwarn",
  "-keep interface scala.ScalaObject",
  "-keep class omegaup.*",
  "-keep class omegaup.data.*",
  "-keep class omegaup.grader.*",
  "-keep class omegaup.runner.*",
  "-keepclassmembers class omegaup.data.* { *; }",
  "-keepclassmembers class omegaup.runner.* { *; }",
  "-keepclassmembers class omegaup.Service { *; }",
  "-keep class scala.collection.JavaConversions",
  "-keep class org.eclipse.jetty.util.log.Slf4jLog",
  "-keep class ch.qos.logback.classic.Logger",
  ProguardOptions.keepMain("omegaup.Service")
)

ProguardKeys.inputFilter in Proguard := { file =>
  file.name match {
    case "grader_2.10-1.1.jar" => None
    case _ => Some("!**/ECLIPSEF.RSA,!**/ECLIPSEF.SF,!about.html,!META-INF/MANIFEST.MF,!rootdoc.txt,!META-INF/services/java.sql.Driver")
  }
}

javaOptions in (Proguard, ProguardKeys.proguard) := Seq("-Xmx2G")
