#!/usr/bin/env groovy

pipeline {

  agent { dockerfile true }
  environment {
    REPO = credentials('magento')
  }

  stages {
    stage('Prepare environment') {
      steps {
        checkout scm
      }
    }

    stage('Update Dependencies') {
      steps {
        sh "composer config repositories.0 composer https://repo.magento.com"
        sh "composer config http-basic.repo.magento.com $REPO_USR $REPO_PSW"
        sh "composer install --no-progress --no-suggest"
      }
    }

    stage('Code Sniffer') {
      steps {
        catchError {
          sh "./vendor/bin/phpcs --standard=ruleset.xml --report=checkstyle --report-file=chkphpcs.xml || true"
        }
      }
    }

    stage('Mess Detection') {
      steps {
        catchError {
          sh "./vendor/bin/phpmd . xml codesize,naming,unusedcode,controversial,design --exclude vendor,var,build,tests --reportfile phpmd.xml || true"
        }
      }
    }

    stage('Phan Analysis') {
      steps {
        catchError {
          sh "./vendor/bin/phan --config-file=phan.php --output-mode=checkstyle --output=chkphan.xml || true"
        }
      }
    }

    stage('Package') {
      steps {
        script {
          version = sh(returnStdout: true, script: 'git rev-parse --short HEAD').trim()
          sh "composer archive --format=zip --file=${version}"
          sh "composer validate-archive -- ${version}.zip"
        }
        archiveArtifacts "${version}.zip"
      }
    }

    stage('Setup') {
      steps {
        script {
          sh "rm -rf *"
          sh "composer create-project magento/community-edition ."
        }
      }
    }

  }

  post {
    always {
      checkstyle pattern: 'chk*.xml', unstableTotalAll:'0'
      pmd pattern: 'phpmd.xml', unstableTotalAll:'0'
      deleteDir()
    }
  }
}
